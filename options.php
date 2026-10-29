<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * @global CMain $APPLICATION
 */

$moduleId = 'roman140176.redirects';
// штатная вкладка прав (group_rights.php) берет модуль из $module_id
$module_id = $moduleId;

$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R') {
    return;
}

$request = Application::getInstance()->getContext()->getRequest();
$formUrl = $APPLICATION->GetCurPage() . '?mid=' . urlencode($moduleId) . '&lang=' . LANGUAGE_ID;

$tabControl = new CAdminTabControl('roman140176_redirects_options', [
    [
        'DIV' => 'log',
        'TAB' => Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_TAB'),
        'TITLE' => Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_TITLE'),
    ],
    [
        'DIV' => 'rights',
        'TAB' => Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_RIGHTS_TAB'),
        'TITLE' => Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_RIGHTS_TITLE'),
    ],
]);

if ($request->isPost() && $request->getPost('Update') !== null && $right === 'W' && check_bitrix_sessid()) {
    Option::set($moduleId, 'log_enabled', $request->getPost('log_enabled') === 'Y' ? 'Y' : 'N');
    // лимит и срок только целые больше нуля, иначе возвращаем значение по умолчанию
    foreach (['log_limit', 'log_days'] as $name) {
        $value = (int)$request->getPost($name);
        Option::set($moduleId, $name, $value > 0 ? (string)$value : Option::getDefault($moduleId, $name));
    }
    Option::set($moduleId, 'log_ignore', trim((string)$request->getPost('log_ignore')));

    // права сохраняет сам group_rights.php, когда видит Update в POST, вывод его тут не нужен
    ob_start();
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights.php';
    ob_end_clean();

    LocalRedirect($formUrl . '&' . $tabControl->ActiveTabParam());
}
?>
<form method="post" action="<?= htmlspecialcharsbx($formUrl) ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><label for="log_enabled"><?= Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_LOG_ENABLED') ?></label></td>
        <td width="60%">
            <input type="checkbox" name="log_enabled" id="log_enabled" value="Y"<?= Option::get($moduleId, 'log_enabled') === 'Y' ? ' checked' : '' ?>>
        </td>
    </tr>
    <tr>
        <td><label for="log_limit"><?= Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_LOG_LIMIT') ?></label></td>
        <td>
            <input type="text" size="10" name="log_limit" id="log_limit" value="<?= (int)Option::get($moduleId, 'log_limit') ?>">
        </td>
    </tr>
    <tr>
        <td><label for="log_days"><?= Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_LOG_DAYS') ?></label></td>
        <td>
            <input type="text" size="10" name="log_days" id="log_days" value="<?= (int)Option::get($moduleId, 'log_days') ?>">
        </td>
    </tr>
    <tr>
        <td class="adm-detail-valign-top"><label for="log_ignore"><?= Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_LOG_IGNORE') ?></label></td>
        <td>
            <textarea name="log_ignore" id="log_ignore" cols="40" rows="10"><?= htmlspecialcharsbx(Option::get($moduleId, 'log_ignore')) ?></textarea><br>
            <small><?= Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_LOG_IGNORE_HINT') ?></small>
        </td>
    </tr>
    <?php $tabControl->BeginNextTab(); ?>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights.php'; ?>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="Update" class="adm-btn-save" value="<?= Loc::getMessage('ROMAN140176_REDIRECTS_OPTIONS_SAVE') ?>"<?= $right < 'W' ? ' disabled' : '' ?>>
    <?php $tabControl->End(); ?>
</form>
