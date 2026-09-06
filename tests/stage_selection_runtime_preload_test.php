<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Calculator {
    // Keep the real dependency collector and hydration pipeline; replace only
    // the Bitrix transport so requested IDs and pinned iblocks are observable.
    class ElementDataService {
        public static array $requests = [];
        public function __construct(array $pins) {}
        public function prepareRefreshPayload(array $requests): array {
            return array_map(static function (array $request): array {
                self::$requests[] = $request;
                return ['data' => array_map(static function (int $id) use ($request): array {
                    $row = ['id' => $id, 'name' => 'Entity ' . $id];
                    if ($request['iblockId'] === 22) $row['productId'] = 900;
                    if ($request['iblockId'] === 24) $row['productId'] = 901;
                    return $row;
                }, $request['ids'])];
            }, $requests);
        }
    }
}
namespace Bitrix\Catalog {
    class ProductTable { public static function getList(array $args): \EmptyCursor { return new \EmptyCursor(); } }
}
namespace {
    class EmptyCursor { public function Fetch(): bool { return false; } }
    class CIBlockProperty { public static function GetList(...$args): EmptyCursor { return new EmptyCursor(); } }
    class CIBlockElement { public static function GetList(...$args): EmptyCursor { return new EmptyCursor(); } }
    class CCatalogGroup { public static function GetBaseGroup(): array { return []; } }

    require_once __DIR__ . '/../lib/Calculator/InitPayloadService.php';
    require_once __DIR__ . '/../lib/Services/StageVariantMappingService.php';
    use Prospektweb\Calc\Calculator\InitPayloadService;
    use Prospektweb\Calc\Calculator\ElementDataService;
    use Prospektweb\Calc\Services\StageVariantMappingService as Mapping;

    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    $service = (new ReflectionClass(InitPayloadService::class))->newInstanceWithoutConstructor();
    $pins = ['CALC_SETTINGS' => 20, 'CALC_OPERATIONS' => 21, 'CALC_OPERATIONS_VARIANTS' => 22,
        'CALC_MATERIALS' => 23, 'CALC_MATERIALS_VARIANTS' => 24, 'CALC_EQUIPMENT' => 25];
    (new ReflectionProperty(InitPayloadService::class, 'pinnedRuntimeIblockIds'))->setValue($service, $pins);
    $tree = static function (string $type, array $ids): array {
        return ['VALUE' => ['TEXT' => htmlspecialchars(json_encode([
            'contract' => Mapping::MATERIAL_DECISION_TREE_CONTRACT,
            'tree' => ['kind' => 'condition', 'source' => ['kind' => 'form_field', 'field_id' => 'global.constant.Dense'],
                'branches' => array_map(static fn(int $id, int $index): array => [
                    'option_id' => (string)$index,
                    'child' => ['kind' => 'result', 'result' => ['entity_type' => $type, 'entity_id' => $id], 'resolution' => 'manual'],
                ], $ids, array_keys($ids)),
            ],
        ], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5, 'UTF-8')]];
    };
    $stage = ['id' => 16430, 'properties' => [
        'OPTIONS_OPERATION' => $tree('operation', [2449, 2450, 2449]),
        'OPTIONS_EQUIPMENT' => $tree('equipment', [500]),
        'OPTIONS_CALCULATOR' => $tree('calculator', [600]),
        'OPTIONS_MATERIAL' => $tree('variant', [700]),
    ]];
    $store = $service->completeStageSelectionStoreReadOnly([
        'CALC_STAGES' => [$stage], 'CALC_OPERATIONS_VARIANTS' => [['id' => 2450, 'productId' => 900, 'name' => 'Kept']],
    ]);
    check(array_column($store['CALC_OPERATIONS_VARIANTS'], 'id') === [2450, 2449], 'all tree variants must load without a static binding, exactly once');
    check($store['CALC_OPERATIONS_VARIANTS'][0]['name'] === 'Kept', 'existing snapshot rows must be retained');
    check(array_column($store['CALC_OPERATIONS'], 'id') === [900], 'operation parent must be available in the logic context');
    check(array_column($store['CALC_MATERIALS'], 'id') === [901], 'material parent must be available in the logic context');
    check(array_column($store['CALC_EQUIPMENT'], 'id') === [500], 'equipment tree candidate must load');
    check(array_column($store['CALC_SETTINGS'], 'id') === [600], 'calculator tree candidate must load');
    check(array_column($store['CALC_MATERIALS_VARIANTS'], 'id') === [700], 'material tree candidate must load');
    $requests = ElementDataService::$requests;
    check(count($requests) === 6, 'only missing explicit candidates and parents must be requested');
    foreach ($requests as $request) check(in_array($request['iblockId'], $pins, true) && $request['ids'] !== [], 'every request must target an exact pinned iblock and explicit IDs');
    $service->completeStageSelectionStoreReadOnly($store);
    check(ElementDataService::$requests === $requests, 'repeated graph completion must not reload known entities');

    $method = new ReflectionMethod(InitPayloadService::class, 'extractStageSelectionReferencesFromStages');
    $rules = ['contract' => Mapping::CONTRACT, 'input_field_ids' => ['method'], 'metric_source' => null, 'metric_keys' => [],
        'rules' => [['input_values' => ['method' => 'digital'], 'metric_ranges' => new stdClass(), 'variant_id' => 800]]];
    $refs = $method->invoke(null, [['properties' => ['OPTIONS_OPERATION' => ['VALUE' => json_encode($rules)]]]]);
    check($refs === [['entity_type' => 'operation_variant', 'entity_id' => 800]], 'ordered operation rules select variants');
    $selection = ['contract' => Mapping::ENTITY_PARAMETER_SELECTION_CONTRACT, 'target' => 'operation',
        'candidates' => [['entity_type' => 'operation', 'entity_id' => 810], ['entity_type' => 'operation_variant', 'entity_id' => 811]],
        'comparisons' => [['parameter_code' => 'entity.id', 'source' => ['kind' => 'constant', 'code' => 'Selected']]],
        'fallback' => ['entity_type' => 'operation_variant', 'entity_id' => 811]];
    $refs = $method->invoke(null, [['properties' => ['OPTIONS_OPERATION' => ['VALUE' => json_encode($selection)]]]]);
    check($refs === [['entity_type' => 'operation', 'entity_id' => 810], ['entity_type' => 'operation_variant', 'entity_id' => 811]], 'parameter selection must preserve parent/variant types and fallback');
    $selection['candidates'] = [];
    $refs = $method->invoke(null, [['properties' => ['OPTIONS_OPERATION' => ['VALUE' => json_encode($selection)]]]]);
    check($refs === [['entity_type' => 'operation_variant', 'entity_id' => 811]], 'ID lookup fallback must be preloaded even without explicit candidates');
    check($method->invoke(null, [['properties' => ['OPTIONS_EQUIPMENT' => $tree('operation', [2449])]]]) === [], 'incompatible tree types must not load from another catalog');
    echo "Stage selection runtime preload tests passed\n";
}
