<?php
/**
 * Шаг 2 установки: Подтверждение создания
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

$productIblockId = (int)($_REQUEST['PRODUCT_IBLOCK_ID'] ?? 0);
$createDemoData = ($_REQUEST['CREATE_DEMO_DATA'] ?? '') === 'Y';

if ($productIblockId <= 0) {
    echo '<div class="adm-info-message adm-info-message-red">' .
        Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SELECT_IBLOCK_ERROR') .
        '</div>';
    echo '<a href="' . $APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&id=prospektweb.calc&install=Y&step=1">' .
        Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_BACK') .
        '</a>';
    return;
}

// Получаем информацию о выбранном инфоблоке
Loader::includeModule('iblock');
Loader::includeModule('catalog');

$arIBlock = \CIBlock::GetByID($productIblockId)->Fetch();
$catalogInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
$skuIblockId = $catalogInfo['IBLOCK_ID'] ?? null;

?>
<form action="<?= $APPLICATION->GetCurPage() ?>" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="3">
    <input type="hidden" name="PRODUCT_IBLOCK_ID" value="<?= $productIblockId ?>">
    <input type="hidden" name="SKU_IBLOCK_ID" value="<?= (int)$skuIblockId ?>">
    <input type="hidden" name="CREATE_DEMO_DATA" value="<?= $createDemoData ? 'Y' : 'N' ?>">

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_STEP2_TITLE') ?></td>
        </tr>

        <tr>
            <td width="40%"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SELECTED_IBLOCK') ?></td>
            <td width="60%">
                <strong><?= htmlspecialcharsbx($arIBlock['NAME'] ?? '') ?></strong> [<?= $productIblockId ?>]
            </td>
        </tr>

        <?php if ($skuIblockId): ?>
        <tr>
            <td><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SKU_IBLOCK') ?></td>
            <td>ID: <?= $skuIblockId ?></td>
        </tr>
        <?php endif; ?>

        <tr>
            <td><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CREATE_DEMO') ?></td>
            <td><?= $createDemoData ? Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_YES') : Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_NO') ?></td>
        </tr>
    </table>

    <div class="adm-info-message">
        <h3><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_WILL_CREATE') ?></h3>
        <ul>
            <li><strong><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_TYPE_CALCULATOR') ?></strong> (calculator)
                <ul>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_CALC_CONFIG') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_CALC_SETTINGS') ?></li>
                </ul>
            </li>
            <li><strong><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_TYPE_CATALOG') ?></strong> (calculator_catalog)
                <ul>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_MATERIALS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_MATERIALS_VARIANTS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_WORKS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_WORKS_VARIANTS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_EQUIPMENT') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_DETAILS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_DETAILS_VARIANTS') ?></li>
                </ul>
            </li>
            <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_PROPERTIES_NOTE') ?></li>
        </ul>
    </div>

    <div style="margin-top: 20px;">
        <a href="<?= $APPLICATION->GetCurPage() ?>?lang=<?= LANGUAGE_ID ?>&id=prospektweb.calc&install=Y&step=1"
           class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_BACK') ?></a>
        <input type="submit" name="install_confirm" value="<?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CONFIRM') ?>" class="adm-btn-save">
    </div>
</form>
