<?php
declare(strict_types=1);

// Exercise the real stage clone path against Bitrix's empty HTML write quirk.
final class StageCloneCursor {
    public function __construct(private array $rows) {}
    public function Fetch() { return array_shift($this->rows) ?: false; }
    public function GetNextElement() { return new class {
        public function GetFields(): array { return ['ID' => 10, 'NAME' => 'Layout', 'ACTIVE' => 'Y']; }
    }; }
}
final class CUtil { public static function translit(...$args): string { return 'layout'; } }
final class CIBlockElement {
    public static array $written = [];
    public static function GetList(...$args): StageCloneCursor { return new StageCloneCursor([]); }
    public function Add(array $fields): int { return 110; }
    public static function GetProperty(...$args): StageCloneCursor {
        return new StageCloneCursor([
            ['CODE' => 'CALC_SETTINGS', 'PROPERTY_TYPE' => 'E', 'VALUE' => 20],
            ['CODE' => 'OPTIONS_CALCULATOR', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'HTML', 'VALUE' => ['TEXT' => '', 'TYPE' => 'HTML']],
            ['CODE' => 'OPTIONS_OPERATION', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'HTML', 'VALUE' => ['TEXT' => '{"contract":"test","rules":[]}', 'TYPE' => 'HTML']],
            ['CODE' => 'AI_CONTEXT_JSON', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'HTML', 'VALUE' => ['TEXT' => '', 'TYPE' => 'TEXT']],
        ]);
    }
    public static function SetPropertyValuesEx(int $id, int $iblock, array $values): void {
        foreach ($values as $key => $value) {
            self::$written[$key] = is_array($value) && ($value['TEXT'] ?? null) === '' ? ['TEXT' => 'HTML', 'TYPE' => 'HTML'] : $value;
        }
    }
}
require_once dirname(__DIR__) . '/lib/Calculator/BundleHandler.php';
$reflection = new ReflectionClass(\Prospektweb\Calc\Calculator\BundleHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('cloneStageElement');
$method->setAccessible(true);
$id = $method->invoke($handler, 10, 30, [20 => 120]);
if ($id !== 110 || isset(CIBlockElement::$written['OPTIONS_CALCULATOR']) || isset(CIBlockElement::$written['AI_CONTEXT_JSON'])) {
    throw new RuntimeException('An empty mapping must remain absent instead of becoming the invalid HTML marker.');
}
if (CIBlockElement::$written['CALC_SETTINGS'] !== [120]
    || CIBlockElement::$written['OPTIONS_OPERATION']['TEXT'] !== '{"contract":"test","rules":[]}') {
    throw new RuntimeException('Stage clone must retain remapped settings and non-empty mapping bytes.');
}
echo "stage_clone_empty_mapping_test: OK\n";
