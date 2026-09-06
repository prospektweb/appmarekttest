<?php
/** Scoped CLI migration. Run only through the authorized site hosting account. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = '/home/c/cq207700/prospektprint.ru/public_html';
if (get_current_user() !== 'cq207700') throw new RuntimeException('Unexpected hosting account');
$_SERVER['REQUEST_METHOD']='GET';
define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
global $USER;
$USER->Authorize(1, false, false);
if (!$USER || !$USER->IsAdmin()) { http_response_code(403); exit('Administrator session required.'); }
if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) throw new RuntimeException('Module unavailable');

use Prospektweb\Calc\Services\CalculatorMutationAuthorityService as Authority;
use Prospektweb\Calc\Services\CalculatorGlobalMutationService as GlobalMutation;
use Prospektweb\Calc\Services\GlobalCalculatorMutationCoordinatorService as Coordinator;
use Prospektweb\Calc\Services\GlobalSymbolService as Symbols;
use Prospektweb\Calc\Services\CalculatorVersionRegistryService as Registry;
use Prospektweb\Calc\Services\CalculatorVersionComponentDocumentService as Components;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService as Sources;
use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService as Bundles;
use Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService as Publications;
use Prospektweb\Calc\Services\ControlCenterEditorsService as Editors;

const PRESET = 12740;
const WORKING = 16411;
const VERSION = 'v_3caf71f29edbb97234c4';

function outputJson(array $value): never {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    exit;
}
function readElement(array $change): array {
    $payload=(new \Prospektweb\Calc\Calculator\ElementDataService((new Authority())->readAuthority()['iblockIds']))->prepareRefreshPayload([['iblockId'=>$change['iblockId'],'ids'=>[$change['id']],'includeParent'=>false]]);
    $e=$payload[0]['data'][0] ?? null;
    if (!$e || $e['id']!==$change['id']) throw new RuntimeException('Missing element ' . $change['id']);
    $p=$e['properties']; $properties = [];
    foreach ($change['properties'] as $code => $_value) {
        if (!isset($p[$code])) throw new RuntimeException('Missing property ' . $code);
        $value=$p[$code]['VALUE'];
        if ($p[$code]['MULTIPLE']==='Y' && !is_array($value)) $value=[];
        $properties[$code] = ['VALUE'=>$value, 'DESCRIPTION'=>$p[$code]['DESCRIPTION'] ?? null];
    }
    $result=['id'=>$e['id'],'iblockId'=>$e['iblockId'],'name'=>$e['name'],'properties'=>$properties];
    if (isset($change['catalogProduct'])) $result['catalogProduct']=['WEIGHT'=>$e['attributes']['weight']];
    return $result;
}
function normalized($v) {
    if ($v === null || $v === false || $v === '') return '';
    if (is_array($v) && array_key_exists('VALUE',$v) && is_array($v['VALUE']) && array_is_list($v['VALUE'])) {
        $v['DESCRIPTION']=array_map(fn($i)=>$v['DESCRIPTION'][$i] ?? '',array_keys($v['VALUE']));
    }
    if (is_array($v)) { foreach ($v as &$item) $item = normalized($item); unset($item); return $v; }
    return (string)$v;
}
function same($a, $b): bool { return Coordinator::hashCanonical(normalized($a)) === Coordinator::hashCanonical(normalized($b)); }
function normalizedGlobals(array $rows): array {
    $out = [];
    foreach ($rows as $row) { unset($row['presetId']); $out[$row['code']] = $row; }
    ksort($out); return $out;
}
function readAndCheck(array $recipe, array $iblocks): array {
    $elements = [];
    foreach ($recipe['changes'] as $change) {
        if (!in_array((int)$change['iblockId'], array_map('intval', $iblocks), true)) throw new RuntimeException('Unpinned iblock');
        $e = readElement($change);
        if (!same($e['name'], $change['expectedName'])) throw new RuntimeException('Name changed: ' . $e['id']);
        foreach ($change['expected'] as $code => $value) {
            if (!same($e['properties'][$code], $value)) throw new RuntimeException('Property changed: ' . $e['id'] . '.' . $code);
        }
        if (isset($change['expectedCatalogProduct']) && !same($e['catalogProduct'],$change['expectedCatalogProduct'])) throw new RuntimeException('Catalog product changed: '.$e['id']);
        $elements[] = $e;
    }
    $globals = (new Symbols())->listReadOnlyFromIblockId((int)$iblocks['CALC_GLOBAL_VALUES'], WORKING);
    if (!same(normalizedGlobals($globals), normalizedGlobals($recipe['expectedGlobals']))) throw new RuntimeException('Global registry changed');
    $retained = array_column($recipe['globals'], 'id');
    foreach ($globals as $row) if (!in_array($row['id'], $retained, true)) throw new RuntimeException('Recipe must retain every existing global ID');
    return ['elements'=>$elements,'globals'=>$globals];
}
function writeElement(array $change): array {
    $e = CIBlockElement::GetList([], ['ID'=>$change['id'], 'IBLOCK_ID'=>$change['iblockId']], false, false, ['*'])->GetNextElement();
    $props = $e->GetProperties();
    foreach ($change['properties'] as $code=>$value) {
        if ($props[$code]['MULTIPLE'] === 'Y') {
            $converted = [];
            foreach ((array)$value['VALUE'] as $i=>$entry) $converted[] = ['VALUE'=>$entry, 'DESCRIPTION'=>$value['DESCRIPTION'][$i] ?? ''];
            CIBlockElement::SetPropertyValuesEx($change['id'], $change['iblockId'], [$code=>$converted ?: false]);
        } elseif ($props[$code]['USER_TYPE'] === 'HTML') {
            CIBlockElement::SetPropertyValues($change['id'], $change['iblockId'], [], $code);
            CIBlockElement::SetPropertyValuesEx($change['id'], $change['iblockId'], [$code=>['VALUE'=>$value['VALUE']]]);
        } else {
            CIBlockElement::SetPropertyValuesEx($change['id'], $change['iblockId'], [$code=>$value['VALUE']]);
        }
    }
    if (isset($change['name'])) {
        $api = new CIBlockElement();
        if (!$api->Update($change['id'], ['NAME'=>$change['name']])) throw new RuntimeException('Rename failed: '.$api->LAST_ERROR);
    }
    if (isset($change['catalogProduct']) && !CCatalogProduct::Update($change['id'],$change['catalogProduct'])) throw new RuntimeException('Catalog product update failed');
    $after = readElement($change);
    foreach ($change['properties'] as $code=>$expected) if (!same($after['properties'][$code], $expected)) throw new RuntimeException('Readback failed: '.$change['id'].'.'.$code);
    if (isset($change['catalogProduct']) && !same($after['catalogProduct'],$change['catalogProduct'])) throw new RuntimeException('Catalog product readback failed');
    return $after;
}

$privateDir = dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '/')) . '/.codex-sheet-offset-20260906';
try {
    if (($argv[1] ?? '') === 'form-fix') {
        $expectedHash=(string)($argv[2] ?? '');
        $result=(new Registry())->coordinateVersionMutation(PRESET,function() use($privateDir,$expectedHash) {
            $bundles=new Bundles(); $bundle=$bundles->load(PRESET,VERSION);
            if (!$bundle || !hash_equals($bundle['contentHash'],$expectedHash)) throw new RuntimeException('Form bundle changed');
            $documents=$bundle['documents']; $form=$documents['form']['formDefinition']; $removed=0;
            foreach ($form['fields'] as &$field) if (($field['fieldId'] ?? '')==='format') {
                foreach ($field['_runtime']['inputs'] as &$input) {
                    $kept=[];
                    foreach ($input['constraint_rules'] ?? [] as $rule) {
                        $conditions=$rule['when']['conditions'] ?? [];
                        $isOffset=count($conditions)===1 && ($conditions[0]['property_code'] ?? '')==='CALC_PROP_METHOD' && ($conditions[0]['values'] ?? [])===['OFSET'];
                        if ($isOffset && $rule['min']===$rule['max'] && in_array($rule['max'],[90,50],true)) $removed++;
                        else $kept[]=$rule;
                    }
                    $input['constraint_rules']=$kept;
                } unset($input);
            } unset($field);
            if ($removed!==2) throw new RuntimeException('Expected two obsolete offset-only format limits');
            $forms=new \Prospektweb\Calc\Services\CalculatorVersionFormDocumentService();
            if (!$forms->has(PRESET,VERSION)) throw new RuntimeException('Version form absent');
            $old=$forms->ensure(PRESET,VERSION,null,[]);
            if (!same($old['formDefinition'],$documents['form']['formDefinition'])) throw new RuntimeException('Standalone and bundle forms differ');
            $backupFile=$privateDir.'/form-before-'.$expectedHash.'.json';
            if (file_exists($backupFile)) throw new RuntimeException('Form repair was already attempted');
            if (file_put_contents($backupFile,json_encode(['bundle'=>$bundle,'form'=>$old],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) throw new RuntimeException('Form backup failed');
            $documents['form']['formDefinition']=$form;
            $preview=(new Editors())->previewVersionFormFirst(PRESET,$form,$old['bindingDefinition'],$documents);
            if (($preview['compile']['valid'] ?? false)!==true) throw new RuntimeException('Updated format form did not compile');
            $bundles->inspect($documents);
            $saved=$forms->saveDraft(PRESET,VERSION,$old['revision'],$form,$old['bindingDefinition']);
            $documents['form']['formDefinition']=$saved['formDefinition'];
            $after=$bundles->save(PRESET,VERSION,$documents);
            return ['status'=>'ok','removedOffsetOnlyLimits'=>$removed,'contentHash'=>$after['contentHash'],'formRevision'=>$saved['revision']];
        });
        file_put_contents($privateDir.'/form-applied.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX);
        outputJson($result);
    }
    if (($argv[1] ?? '') === 'paper-inventory') {
        $iblocks=(new Authority())->readAuthority()['iblockIds']; $papers=[];
        $rs=CIBlockElement::GetList(['ID'=>'ASC'],['IBLOCK_ID'=>$iblocks['CALC_MATERIALS_VARIANTS'],'ACTIVE'=>'Y'],false,false,['ID','NAME','IBLOCK_ID']);
        while($r=$rs->Fetch()) if(preg_match('/(120|глян|gloss)/ui',$r['NAME'])) $papers[]=$r;
        outputJson(['papers'=>$papers]);
    }
    if (($argv[1] ?? '') === 'diagnostics') {
        $iblocks=(new Authority())->readAuthority()['iblockIds'];$iblock=$iblocks['CALC_STAGES'];
        $e=CIBlockElement::GetList([],['ID'=>16430,'IBLOCK_ID'=>$iblock],false,false,['ID'])->GetNextElement();
        $minimal=$e->GetFields();$minimalProps=$e->GetProperties([],['CODE'=>'CALC_SETTINGS']);
        $e=CIBlockElement::GetList([],['ID'=>16430,'IBLOCK_ID'=>$iblock],false,false,['ID','IBLOCK_ID'])->GetNextElement();
        outputJson(['minimal'=>$minimal,'minimalProps'=>$minimalProps,'fullFields'=>$e->GetFields(),'fullProps'=>$e->GetProperties([],['CODE'=>'CALC_SETTINGS'])]);
    }
    if (($argv[1] ?? '') === 'catalog') {
        // Read-only inventory of the two relevant catalog families, using the module loader.
        $iblocks = (new Authority())->readAuthority()['iblockIds'];
        $ids = [];
        foreach (['CALC_MATERIALS_VARIANTS','CALC_OPERATIONS_VARIANTS'] as $key) {
            $rs = CIBlockElement::GetList(['ID'=>'ASC'], ['IBLOCK_ID'=>$iblocks[$key],'ACTIVE'=>'Y'], false, false, ['ID','NAME','IBLOCK_ID']);
            while ($r = $rs->Fetch()) if (preg_match('/(короб|упаков|Komi|офсетная)/ui', $r['NAME'])) $ids[$key][] = (int)$r['ID'];
        }
        $requests=[]; foreach($ids as $key=>$list) $requests[]=['iblockId'=>$iblocks[$key],'ids'=>$list,'includeParent'=>false];
        outputJson(['catalog'=>(new \Prospektweb\Calc\Calculator\ElementDataService($iblocks))->prepareRefreshPayload($requests)]);
    }
    $raw = file_get_contents($privateDir . '/recipe.json');
    $recipe = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (($recipe['contract'] ?? '') !== 'prospektweb.calc.sheet-offset-recipe/v1' || $recipe['presetId'] !== PRESET || $recipe['workingPresetId'] !== WORKING || $recipe['versionId'] !== VERSION) throw new RuntimeException('Wrong recipe scope');
    $sha = hash('sha256', $raw);
    $components = new Components();
    $current = $components->load(PRESET, VERSION, 'logic');
    if (($current['document']['workingPresetId'] ?? 0) !== WORKING || ($current['document']['workingVersionId'] ?? '') !== VERSION) throw new RuntimeException('Working graph identity mismatch');
    $global = (new GlobalMutation())->currentAuthority();
    if ($global['revision'] !== $recipe['expectedGlobalRevision'] || $global['fingerprint'] !== $recipe['expectedGlobalFingerprint']) throw new RuntimeException('Global revision changed');
    $pinned = (new Authority())->readAuthority()['iblockIds'];
    $before = readAndCheck($recipe, $pinned);
    if (($argv[1] ?? '') === 'preview') {
        $preview=['sha'=>$sha,'contentHash'=>$current['contentHash'],'logicHash'=>$current['componentHash'],'elements'=>array_map(fn($e)=>['id'=>$e['id'],'name'=>$e['name']],$before['elements']),'globalsBefore'=>count($before['globals']),'globalsAfter'=>count($recipe['globals'])];
        file_put_contents($privateDir.'/preview.json',json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
        outputJson($preview);
    }
    $preview=json_decode(file_get_contents($privateDir.'/preview.json'),true,512,JSON_THROW_ON_ERROR);
    if (($argv[1] ?? '') !== 'apply' || !hash_equals($sha,(string)($argv[2] ?? '')) || !hash_equals($sha,$preview['sha'])) throw new RuntimeException('Exact reviewed recipe SHA is required');
    $_POST=['contentHash'=>$preview['contentHash'],'logicHash'=>$preview['logicHash']];
    if (file_exists($privateDir.'/applied-'.$sha.'.json')) throw new RuntimeException('This maintenance package was already applied');
    $result = (new Coordinator())->mutate($recipe['expectedGlobalRevision'], $recipe['expectedGlobalFingerprint'], function($_globalAuthority,$connection) use($recipe,$privateDir,$sha,$components) {
        return (new Registry())->coordinateVersionMutation(PRESET, function() use($connection,$recipe,$privateDir,$sha,$components) {
            $editors = new Editors(); $identity=$editors->validatePresetLaunch(PRESET);
            $workspace=(new Registry())->loadWorkspace(PRESET,$identity['presetName'],$editors->loadFormFirstWorkspace(PRESET),['id'=>(int)$GLOBALS['USER']->GetID(),'name'=>'Администратор']);
            $version=array_values(array_filter($workspace['versions'],fn($r)=>$r['versionId']===VERSION))[0] ?? null;
            if (!$version || $version['status']==='ARCHIVED') throw new RuntimeException('Version is not editable');
            $current=$components->load(PRESET,VERSION,'logic');
            if (!hash_equals($current['contentHash'],$_POST['contentHash']) || !hash_equals($current['componentHash'],$_POST['logicHash'])) throw new RuntimeException('Version changed after preview');
            $authority=new Authority();
            return $authority->withAuthorityInTransaction($connection,WORKING,function($_flag,$iblocks) use($authority,$recipe,$privateDir,$sha,$components,$current) {
                $before=readAndCheck($recipe,$iblocks);
                $graph=$authority->readLockedPresetGraph(WORKING);
                $settingsStages=[];
                foreach($recipe['changes'] as $change) {
                    if ($change['iblockId']===$iblocks['CALC_SETTINGS']) {
                        $owners=[];
                        foreach ($graph['stageSettings'] as $stageId=>$ids) if (in_array($change['id'],$ids,true)) $owners[]=(int)$stageId;
                        if (count($owners)!==1) throw new RuntimeException('Expected exactly one local stage for settings '.$change['id']);
                        $settingsStages[$change['id']]=$owners[0];
                        $authority->assertContractCloneAllowed(WORKING,$owners[0],$change['id']);
                    }
                    if ($change['iblockId']===$iblocks['CALC_PRESETS']) $authority->assertPresetMutationAllowed(WORKING,$change['id']);
                    if ($change['iblockId']===$iblocks['CALC_STAGES']) $authority->assertStageStructuralMutationAllowed(WORKING,$change['id'],false,'sheet_offset_recipe');
                }
                $backup=['recipeSha256'=>$sha,'before'=>$before,'bundle'=>(new Bundles())->load(PRESET,VERSION)];
                $backupFile=$privateDir.'/before-'.$sha.'.json';
                if (file_exists($backupFile)) {
                    $previous=json_decode(file_get_contents($backupFile),true,512,JSON_THROW_ON_ERROR);
                    if (!same($previous['before'],$before) || ($previous['bundle']['contentHash'] ?? '')!==$current['contentHash']) throw new RuntimeException('Previous attempt backup differs from current state');
                } elseif (file_put_contents($backupFile,json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Backup write failed');
                (new Publications())->freezeLegacyActiveForEditing(PRESET,VERSION);
                $clones=[];
                foreach($settingsStages as $sourceId=>$stageId) {
                    $cloned=(new \Prospektweb\Calc\Services\CalculatorContractService($iblocks))->resolve($sourceId,$stageId,WORKING,'clone','Офсетный блок и обработка листовой продукции');
                    if (($cloned['status'] ?? '')!=='ok') throw new RuntimeException($cloned['message'] ?? 'Settings clone failed');
                    $clones[$sourceId]=(int)$cloned['settingsId'];
                    $authority->refreshLockedState(WORKING);
                }
                $after=[]; foreach($recipe['changes'] as $change) {
                    if (isset($clones[$change['id']])) {
                        $change['id']=$clones[$change['id']];
                        if (isset($change['properties']['LOGIC_JSON'])) $authority->assertSettingsLogicWrite(WORKING,$change['id'],$change['properties']['LOGIC_JSON']['VALUE']['TEXT']);
                    }
                    $after[]=writeElement($change);
                }
                $savedGlobals=(new Symbols())->saveLocked($recipe['globals'],WORKING,$authority,$iblocks);
                $authority->refreshLockedState(WORKING);
                $saved=$components->saveDraft(PRESET,VERSION,'logic',$current['contentHash'],$current['componentHash'],(new Sources())->captureLogic(WORKING,PRESET,VERSION));
                $affected=[];$rs=CIBlockElement::GetList([],['IBLOCK_ID'=>$iblocks['CALC_PRESETS']],false,false,['ID']);while($r=$rs->Fetch())$affected[]=(int)$r['ID'];
                return ['before'=>$before,'after'=>['elements'=>$after,'globals'=>$savedGlobals['symbols'],'settingsClones'=>$clones],'affected_preset_ids'=>$affected,
                    'result'=>['status'=>'ok','recipeSha256'=>$sha,'contentHash'=>$saved['contentHash'],'componentHash'=>$saved['componentHash'],'elements'=>count($after),'globals'=>count($savedGlobals['symbols']),'settingsClones'=>$clones]];
            });
        });
    },['action'=>'apply_sheet_offset_recipe','entity_type'=>'calculator_working_recipe','entity_id'=>(string)WORKING]);
    file_put_contents($privateDir.'/applied-'.$sha.'.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
    outputJson($result);
} catch (Throwable $e) { http_response_code(409); outputJson(['status'=>'error','message'=>$e->getMessage()]); }
