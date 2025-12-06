<?php
/**
 * Шаг 1 установки: Выбор инфоблока товаров
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    echo '<div class="adm-info-message adm-info-message-red">' .
        Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_MODULES_ERROR') .
        '</div>';
    return;
}

// Получаем список каталогов
$catalogs = [];
$rsIBlocks = \CIBlock::GetList(
    ['NAME' => 'ASC'],
    ['TYPE' => 'catalog', 'ACTIVE' => 'Y']
);

while ($arIBlock = $rsIBlocks->Fetch()) {
    $catalogInfo = \CCatalogSKU::GetInfoByProductIBlock($arIBlock['ID']);

    $catalogs[] = [
        'ID' => $arIBlock['ID'],
        'NAME' => $arIBlock['NAME'],
        'CODE' => $arIBlock['CODE'],
        'SKU_IBLOCK_ID' => $catalogInfo['IBLOCK_ID'] ?? null,
    ];
}

?>
<form action="<?= $APPLICATION->GetCurPage() ?>" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_STEP1_TITLE') ?></td>
        </tr>

        <tr>
            <td width="40%">
                <label for="PRODUCT_IBLOCK_ID"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_PRODUCT_IBLOCK') ?></label>
            </td>
            <td width="60%">
                <select name="PRODUCT_IBLOCK_ID" id="PRODUCT_IBLOCK_ID" class="adm-input" style="width: 300px;">
                    <option value=""><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SELECT_IBLOCK') ?></option>
                    <?php foreach ($catalogs as $catalog): ?>
                        <option value="<?= $catalog['ID'] ?>" data-sku-id="<?= $catalog['SKU_IBLOCK_ID'] ?>">
                            <?= htmlspecialcharsbx($catalog['NAME']) ?> [<?= $catalog['ID'] ?>]
                            <?php if ($catalog['SKU_IBLOCK_ID']): ?>
                                (<?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_HAS_SKU') ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="sku_info" style="margin-top: 5px; color: #666;"></div>
            </td>
        </tr>

        <tr>
            <td>
                <label for="CREATE_DEMO_DATA"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CREATE_DEMO') ?></label>
            </td>
            <td>
                <input type="checkbox" name="CREATE_DEMO_DATA" id="CREATE_DEMO_DATA" value="Y" checked>
                <label for="CREATE_DEMO_DATA" class="adm-checkbox-label"></label>
            </td>
        </tr>
    </table>

    <div class="adm-info-message">
        <?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_STEP1_INFO') ?>
    </div>

    <div style="margin-top: 20px;">
        <input type="submit" name="install_next" value="<?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_NEXT') ?>" class="adm-btn-save">
    </div>
</form>

<script>
document.getElementById('PRODUCT_IBLOCK_ID').addEventListener('change', function() {
    var option = this.options[this.selectedIndex];
    var skuId = option.getAttribute('data-sku-id');
    var infoDiv = document.getElementById('sku_info');

    if (skuId && skuId !== 'null' && skuId !== '') {
        infoDiv.innerHTML = '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SKU_DETECTED')) ?>' + skuId;
    } else {
        infoDiv.innerHTML = '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_NO_SKU')) ?>';
    }
});
</script>
