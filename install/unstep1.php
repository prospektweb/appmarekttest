<?php
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

global $APPLICATION;
?>

<form action="<?= $APPLICATION->GetCurPage() ?>" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <div class="adm-info-message">
        <?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_CONFIRM') ?>
    </div>

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TITLE') ?></td>
        </tr>
        <tr>
            <td colspan="2">
                <label>
                    <input type="checkbox" name="DELETE_DATA" value="Y">
                    <?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_DATA') ?>
                </label>
                <br><br>
                <small style="color:#666;"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_DATA_NOTE') ?></small>
            </td>
        </tr>
    </table>

    <div style="margin-top: 20px;">
        <input type="submit" name="uninstall_confirm" value="<?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_CONFIRM_BTN') ?>" class="adm-btn-save">
        <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_CANCEL') ?></a>
    </div>
</form>
