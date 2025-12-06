<?php
/**
 * Страница настроек модуля prospektweb.calc
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

$module_id = 'prospektweb.calc';

if (!Loader::includeModule($module_id)) {
    ShowError(Loc::getMessage('PROSPEKTWEB_CALC_MODULE_NOT_INSTALLED'));
    return;
}

use Prospektweb\Calc\Config\SettingsManager;

global $USER;

if (!$USER->IsAdmin()) {
    return;
}

$settingsManager = new SettingsManager();

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $settings = [
        'priceTypeId' => (int)($_POST['DEFAULT_PRICE_TYPE_ID'] ?? 1),
        'currency' => (string)($_POST['DEFAULT_CURRENCY'] ?? 'RUB'),
        'loggingEnabled' => ($_POST['LOGGING_ENABLED'] ?? 'N') === 'Y',
    ];

    $settingsManager->saveAllSettings($settings);

    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&saved=Y');
}

// Получаем текущие настройки
$currentSettings = $settingsManager->getAllSettings();

// Получаем список типов цен
$priceTypes = [];
if (Loader::includeModule('catalog')) {
    $priceTypeList = \CCatalogGroup::GetListArray();
    foreach ($priceTypeList as $type) {
        $priceTypes[(int)$type['ID']] = $type['NAME'] ?? ('ID ' . $type['ID']);
    }
}

// Получаем список валют
$currencies = [];
if (Loader::includeModule('currency')) {
    $currencyList = \Bitrix\Currency\CurrencyManager::getCurrencyList();
    foreach ($currencyList as $code => $name) {
        $currencies[$code] = $name;
    }
} else {
    $currencies = ['RUB' => 'Рубль', 'USD' => 'Доллар США', 'EUR' => 'Евро'];
}

// Вывод сообщения об успешном сохранении
if ($_GET['saved'] === 'Y') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('PROSPEKTWEB_CALC_SETTINGS_SAVED'),
        'TYPE' => 'OK',
    ]);
}

// Создаём вкладки
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_MAIN_TITLE')],
    ['DIV' => 'edit2', 'TAB' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS'), 'TITLE' => Loc::getMessage('PROSPEKTWEB_CALC_TAB_IBLOCKS_TITLE')],
]);

$tabControl->Begin();

?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= Loc::getMessage('PROSPEKTWEB_CALC_DEFAULT_PRICE_TYPE') ?></td>
        <td width="60%">
            <select name="DEFAULT_PRICE_TYPE_ID">
                <?php foreach ($priceTypes as $id => $name): ?>
                <option value="<?= $id ?>" <?= $currentSettings['priceTypeId'] == $id ? 'selected' : '' ?>>
                    <?= htmlspecialcharsbx($name) ?> [<?= $id ?>]
                </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_DEFAULT_CURRENCY') ?></td>
        <td>
            <select name="DEFAULT_CURRENCY">
                <?php foreach ($currencies as $code => $name): ?>
                <option value="<?= htmlspecialcharsbx($code) ?>" <?= $currentSettings['currency'] == $code ? 'selected' : '' ?>>
                    <?= htmlspecialcharsbx($name) ?> (<?= $code ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <td><?= Loc::getMessage('PROSPEKTWEB_CALC_LOGGING_ENABLED') ?></td>
        <td>
            <input type="checkbox" name="LOGGING_ENABLED" value="Y" <?= $currentSettings['loggingEnabled'] ? 'checked' : '' ?>>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>

    <?php
    $iblockCodes = [
        'CALC_CONFIG' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_CONFIG'),
        'CALC_SETTINGS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_CALC_SETTINGS'),
        'CALC_MATERIALS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS'),
        'CALC_MATERIALS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_MATERIALS_VARIANTS'),
        'CALC_WORKS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_WORKS'),
        'CALC_WORKS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_WORKS_VARIANTS'),
        'CALC_EQUIPMENT' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_EQUIPMENT'),
        'CALC_DETAILS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_DETAILS'),
        'CALC_DETAILS_VARIANTS' => Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_DETAILS_VARIANTS'),
    ];

    foreach ($iblockCodes as $code => $label):
        $iblockId = (int)Option::get($module_id, 'IBLOCK_' . $code, 0);
    ?>
    <tr>
        <td width="40%"><?= htmlspecialcharsbx($label) ?></td>
        <td width="60%">
            <?php if ($iblockId > 0): ?>
                <a href="/bitrix/admin/iblock_list_admin.php?IBLOCK_ID=<?= $iblockId ?>&type=calculator&lang=<?= LANGUAGE_ID ?>">
                    ID: <?= $iblockId ?>
                </a>
            <?php else: ?>
                <span style="color: #999;"><?= Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_NOT_CREATED') ?></span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>

    <?php
    $tabControl->Buttons([
        'disabled' => false,
        'back_url' => '/bitrix/admin/module_admin.php?lang=' . LANGUAGE_ID,
    ]);
    ?>

    <?php $tabControl->End(); ?>
</form>
