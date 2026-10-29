<?php

use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
?>
<form action="<?= $APPLICATION->GetCurPage() ?>" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="roman140176.redirects">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <?php CAdminMessage::ShowMessage(Loc::getMessage('ROMAN140176_REDIRECTS_UNSTEP1_WARNING')); ?>
    <p>
        <label>
            <input type="checkbox" name="keep_data" value="Y" checked>
            <?= Loc::getMessage('ROMAN140176_REDIRECTS_UNSTEP1_KEEP_DATA') ?>
        </label>
    </p>
    <input type="submit" name="uninst" value="<?= Loc::getMessage('ROMAN140176_REDIRECTS_UNSTEP1_SUBMIT') ?>">
</form>
