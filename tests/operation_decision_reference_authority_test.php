<?php

declare(strict_types=1);

use Prospektweb\Calc\Calculator\ElementDataService;
use Prospektweb\Calc\Services\StageVariantMappingService;

final class CIBlockElement
{
    public static array $lookups = [];
    public static array $catalog = [701 => 42, 700 => 41, 900 => 99];

    public static function GetList($order, $filter, $group, $navigation, $select): object
    {
        self::$lookups[] = $filter;
        $exists = (self::$catalog[$filter['ID']] ?? null) === $filter['IBLOCK_ID'];
        return new class($exists ? $filter : false) {
            public function __construct(private $row) {}
            public function Fetch() { return $this->row; }
        };
    }
}

require_once dirname(__DIR__) . '/lib/Services/StageVariantMappingService.php';
require_once dirname(__DIR__) . '/lib/Calculator/ElementDataService.php';

$service = new StageVariantMappingService();
$method = new ReflectionMethod(ElementDataService::class, 'assertOperationDecisionReferences');
$check = static function (array $document, int $parent = 41, int $variants = 42) use ($service, $method): void {
    $json = $service->normalizeMaterialJson(json_encode($document, JSON_THROW_ON_ERROR));
    $method->invoke(null, $json, $parent, $variants);
};
$rejects = static function (callable $run): void {
    try { $run(); } catch (RuntimeException $error) {
        if ($error->getCode() === 409) { return; }
        throw $error;
    }
    throw new RuntimeException('Expected exact pinned catalog rejection');
};
$tree = static fn(int $id): array => [
    'contract' => StageVariantMappingService::MATERIAL_DECISION_TREE_CONTRACT,
    'tree' => [
        'kind' => 'condition',
        'source' => ['kind' => 'form_field', 'field_id' => 'color.scheme'],
        'branches' => [[
            'option_id' => '4+0',
            'child' => [
                'kind' => 'result',
                'result' => ['entity_type' => 'operation', 'entity_id' => $id],
                'resolution' => 'manual',
            ],
        ]],
    ],
];
$selection = static fn(string $type, int $id): array => [
    'contract' => StageVariantMappingService::ENTITY_PARAMETER_SELECTION_CONTRACT,
    'target' => 'operation',
    'candidates' => [],
    'comparisons' => [[
        'parameter_code' => 'entity.id',
        'source' => ['kind' => 'variable', 'code' => 'selected_operation_id'],
    ]],
    'fallback' => ['entity_type' => $type, 'entity_id' => $id],
];

$check($tree(701));
$check($selection('operation', 700));
$check($selection('operation_variant', 701));
if (array_column(CIBlockElement::$lookups, 'IBLOCK_ID') !== [42, 41, 42]) {
    throw new RuntimeException('Tree variants and parameter-selection parents must use their exact catalogs');
}
$rejects(static fn() => $check($tree(700)));
$rejects(static fn() => $check($tree(900)));
$rejects(static fn() => $check($tree(999)));
$rejects(static fn() => $check($tree(701), 41, 0));
$rejects(static fn() => $check($selection('operation', 701)));
$rejects(static fn() => $check($selection('operation_variant', 700)));
$rejects(static fn() => $check($selection('operation_variant', 900)));

$source = file_get_contents(dirname(__DIR__) . '/lib/Calculator/ElementDataService.php');
$start = strpos($source, "} elseif (\$propertyCode === 'OPTIONS_OPERATION') {");
if ($start === false || !str_contains(substr($source, $start, 450), 'self::assertOperationDecisionReferences(')) {
    throw new RuntimeException('OPTIONS_OPERATION writes must use contract-aware authority validation');
}
fwrite(STDOUT, "OK\n");
