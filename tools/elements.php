<?php
/**
 * API endpoint: Поиск элементов инфоблока
 * GET /local/modules/prospektweb.calc/tools/elements.php?iblock=XXX&search=XXX
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

global $APPLICATION;
$APPLICATION->RestartBuffer();

global $USER;

header('Content-Type: application/json; charset=UTF-8');

if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    echo json_encode(['error' => 'access_denied']);
    die();
}

if (!Loader::includeModule('iblock')) {
    echo json_encode(['error' => 'iblock_not_loaded']);
    die();
}

$iblockId = (int)($_GET['iblock'] ?? 0);
$search = trim($_GET['search'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$ids = $_GET['ids'] ?? '';
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

try {
    $filter = ['ACTIVE' => 'Y'];

    if ($iblockId > 0) {
        $filter['IBLOCK_ID'] = $iblockId;
    }

    if ($id > 0) {
        $filter['ID'] = $id;
    } elseif (!empty($ids)) {
        $idsArray = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idsArray)) {
            $filter['ID'] = $idsArray;
        }
    } elseif (!empty($search)) {
        $filter['%NAME'] = $search;
    }

    $elements = [];

    $res = \CIBlockElement::GetList(
        ['NAME' => 'ASC'],
        $filter,
        false,
        ['nTopCount' => $limit],
        ['ID', 'NAME', 'IBLOCK_ID', 'CODE']
    );

    while ($arElement = $res->Fetch()) {
        $elements[] = [
            'id' => (int)$arElement['ID'],
            'name' => $arElement['NAME'],
            'iblockId' => (int)$arElement['IBLOCK_ID'],
            'code' => $arElement['CODE'] ?? '',
        ];
    }

    echo json_encode([
        'success' => true,
        'items' => $elements,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode([
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

die();
