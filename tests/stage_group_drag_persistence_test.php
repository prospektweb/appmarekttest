<?php
namespace Bitrix\Main {
    class Application { public static function getConnection() { return $GLOBALS['connection']; } }
}
namespace {
    final class DragRows {
        private array $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function Fetch() { return array_shift($this->rows) ?: false; }
        public function GetNextElement() { return new class {
            public function GetProperties() { return ['STAGE_GROUPS' => ['VALUE' => ['TEXT' => $GLOBALS['storedGroups']]]]; }
        }; }
    }
    class CIBlockProperty { public static function GetList(...$args) { return new DragRows([['ID' => 1]]); } }
    class CIBlockElement {
        public static function GetList($sort, $filter, ...$args) { return new DragRows([['ID' => (int)$filter['ID']]]); }
        public static function GetProperty($iblock, $id, $sort, $filter) {
            $ids = $GLOBALS['properties'][$id][$filter['CODE']] ?? [];
            return new DragRows(array_map(static fn($id) => ['VALUE' => $id], $ids));
        }
        public static function SetPropertyValues($id, $iblock, $value, $code) { $GLOBALS['storedGroups'] = ''; }
        public static function SetPropertyValuesEx($id, $iblock, $properties) {
            if (!$GLOBALS['failWrite']) $GLOBALS['storedGroups'] = $properties['STAGE_GROUPS']['VALUE']['TEXT'];
        }
    }
    $USER = new class { public function IsAdmin() { return true; } };
    $storedGroups = '';
    $failWrite = false;
    $properties = [10 => ['CALC_DETAILS' => [20]], 20 => ['CALC_STAGES' => [1, 2, 3], 'DETAILS' => []]];
    $connection = new class {
        private string $backup = '';
        public function startTransaction() { $this->backup = $GLOBALS['storedGroups']; }
        public function commitTransaction() {}
        public function rollbackTransaction() { $GLOBALS['storedGroups'] = $this->backup; }
    };
    require_once __DIR__ . '/../lib/Services/StageGroupService.php';
    require_once __DIR__ . '/../lib/Calculator/BundleHandler.php';
    $service = new \Prospektweb\Calc\Services\StageGroupService(['CALC_PRESETS' => 1, 'CALC_DETAILS' => 2, 'CALC_STAGES' => 3]);
    $assert = static function ($condition, $message) { if (!$condition) throw new \RuntimeException($message); };
    $rejects = static function ($callback, $part) use ($assert) {
        try { $callback(); } catch (\Throwable $error) { $assert(strpos($error->getMessage(), $part) !== false, $error->getMessage()); return; }
        throw new \RuntimeException('Expected rejection: ' . $part);
    };
    $draft = ['id' => 'draft', 'title' => 'Заготовка', 'description' => '', 'stageIds' => [], 'parentId' => null, 'detailId' => 20];
    $saved = $service->save(['presetId' => 10, 'groups' => [$draft]])['groups'];
    $assert($saved[0]['stageIds'] === [] && $saved[0]['detailId'] === 20, 'Empty group must persist with its column');
    $singleton = array_replace($draft, ['stageIds' => [1]]);
    $singleton['activationCondition'] = ['version' => 2, 'enabled' => true, 'mode' => 'and', 'operands' => [['kind' => 'input', 'code' => 'protection']]];
    $saved = $service->save(['presetId' => 10, 'groups' => [$singleton]])['groups'];
    $assert($saved[0]['activationCondition'] === $singleton['activationCondition'], 'Group activation must survive storage');
    $rejects(fn() => $service->save(['presetId' => 10, 'groups' => [array_replace($singleton, ['activationCondition' => ['version' => 2, 'enabled' => true, 'mode' => 'and', 'operands' => []]])]]), 'Выберите значение');
    $assert($saved[0]['stageIds'] === [1], 'Singleton group must persist');
    $child = array_replace($draft, ['id' => 'child', 'parentId' => 'draft']);
    $saved = $service->save(['presetId' => 10, 'groups' => [$singleton, $child]])['groups'];
    $assert($saved[1]['parentId'] === 'draft', 'Empty subgroup must retain its parent');
    $request = ['presetId' => 10, 'detailId' => 20, 'stageGroups' => $saved, 'expectedStageGroups' => $saved, 'expectedSorting' => [1, 2, 3]];
    $service->assertDragSnapshot($request);
    $rejects(fn() => $service->assertDragSnapshot(array_replace($request, ['expectedStageGroups' => []])), 'Состав групп изменился');
    $rejects(fn() => $service->assertDragSnapshot(array_replace($request, ['expectedSorting' => [2, 1, 3]])), 'Порядок этапов изменился');
    $rejects(fn() => $service->save(['presetId' => 10, 'groups' => [array_replace($draft, ['detailId' => 99])]]), 'Колонка группы');
    $rejects(fn() => $service->save(['presetId' => 10, 'groups' => [array_replace($draft, ['stageIds' => [1, 3]])]]), 'идти подряд');
    $before = $storedGroups;
    $failWrite = true;
    $rejects(fn() => $service->save(['presetId' => 10, 'groups' => [$draft]]), 'не были записаны');
    $assert($storedGroups === $before, 'A failed group write must roll back');
    $failWrite = false;

    $reflection = new \ReflectionClass(\Prospektweb\Calc\Calculator\BundleHandler::class);
    $bundle = $reflection->newInstanceWithoutConstructor();
    $remap = $reflection->getMethod('remapStageGroupsValue');
    $copy = json_decode($remap->invoke($bundle, json_encode(['version' => 3, 'groups' => [$draft, $singleton]]), [1 => 11], [20 => 120]), true);
    $assert($copy['groups'][0]['detailId'] === 120 && $copy['groups'][0]['stageIds'] === [], 'Clone must remap empty group column');
    $assert($copy['groups'][1]['stageIds'] === [11], 'Clone must retain stage remapping');

    require_once __DIR__ . '/../lib/Services/CalculatorVersionWorkingGraphRehydrator.php';
    $rehydrator = new \ReflectionClass(\Prospektweb\Calc\Services\CalculatorVersionWorkingGraphRehydrator::class);
    $copy = json_decode($rehydrator->getMethod('remapStageGroupsJson')->invoke(null,
        json_encode(['groups' => [$draft]]), [], [20 => 120]), true);
    $assert($copy['groups'][0]['detailId'] === 120, 'Version rehydration must remap an empty group column');

    require_once __DIR__ . '/../lib/Services/DetailHandler.php';
    $detailClass = new \ReflectionClass(\Prospektweb\Calc\Services\DetailHandler::class);
    $detailHandler = $detailClass->newInstanceWithoutConstructor();
    $detailClass->getProperty('presetsIblockId')->setValue($detailHandler, 1);
    $storedGroups = json_encode(['groups' => [$draft, $child]]);
    $detailClass->getMethod('cloneStageGroupsForStageMap')->invoke($detailHandler, 10, [], 120, [20 => 120]);
    $copies = array_slice(json_decode($storedGroups, true)['groups'], 2);
    $assert(count($copies) === 2 && $copies[0]['detailId'] === 120 && $copies[1]['detailId'] === 120
        && $copies[1]['parentId'] === $copies[0]['id'], 'Empty column clone must preserve groups and nesting');

    $route = file_get_contents(__DIR__ . '/../lib/Calculator/ElementDataService.php');
    foreach (['changeSortStage', 'moveStage'] as $action) {
        $start = strpos($route, "case '" . $action . "':");
        $end = strpos($route, 'continue 2;', $start);
        $block = substr($route, $start, $end - $start);
        $assert(strpos($block, 'withAuthorityLock') !== false && strpos($block, 'assertDragSnapshot($request)') !== false
            && strpos($block, "'groups' => \$request['stageGroups']], false)") !== false, 'Order and membership must use one transaction: ' . $action);
    }
    echo "Stage group drag persistence tests passed\n";
}
