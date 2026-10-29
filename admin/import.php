<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;
use Roman140176\Redirects\Import\Importer;
use Roman140176\Redirects\Model\RuleTable;

/** @global CMain $APPLICATION */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'roman140176.redirects';
$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' || !Loader::includeModule($moduleId)) {
    $APPLICATION->AuthForm(Loc::getMessage('ROMAN140176_REDIRECTS_ACCESS_DENIED'));
}
$canEdit = $right >= 'W';

// таблицу на тысячи правил это не ограничивает: 2 МБ это десятки тысяч строк
$maxSize = 2 * 1024 * 1024;

$request = Application::getInstance()->getContext()->getRequest();
$sites = array_column(SiteTable::getList(['select' => ['LID', 'NAME'], 'order' => ['SORT' => 'ASC']])->fetchAll(), 'NAME', 'LID');
$siteId = isset($sites[(string)$request->getPost('SITE_ID')]) ? (string)$request->getPost('SITE_ID') : (string)array_key_first($sites);
$status = in_array((int)$request->getPost('STATUS'), RuleTable::STATUSES, true) ? (int)$request->getPost('STATUS') : 301;
$errors = [];
$result = null;

if ($request->isPost() && $request->getPost('import') !== null) {
    $file = $request->getFile('CSV_FILE');
    $error = is_array($file) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;

    if (!$canEdit || !check_bitrix_sessid()) {
        $errors[] = Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_NO_RIGHTS');
    } elseif ($error === UPLOAD_ERR_NO_FILE) {
        $errors[] = Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_NO_FILE');
    } elseif (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$file['size'] > $maxSize) {
        $errors[] = Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_TOO_BIG');
    } elseif ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $errors[] = Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_UPLOAD_FAILED');
    } else {
        $result = (new Importer())->import((string)file_get_contents($file['tmp_name']), $siteId, $status);
    }
}

$tabControl = new CAdminTabControl('roman140176_redirects_import', [
    ['DIV' => 'import', 'TAB' => Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_TAB'), 'TITLE' => Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_TAB_TITLE')],
]);

$APPLICATION->SetTitle(Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($errors !== []) {
    CAdminMessage::ShowMessage(['MESSAGE' => implode('<br>', array_map('htmlspecialcharsbx', $errors)), 'TYPE' => 'ERROR', 'HTML' => true]);
}

if ($result !== null) {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_ADDED', ['#COUNT#' => $result->added]),
        'TYPE' => 'OK',
    ]);
    if ($result->errors !== []) {
        $lines = array_map(static fn (array $item): string => Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_LINE', [
            '#LINE#' => $item['line'],
            '#TEXT#' => htmlspecialcharsbx($item['message']),
        ]), $result->errors);
        CAdminMessage::ShowMessage([
            'MESSAGE' => Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_SKIPPED', ['#COUNT#' => count($result->errors)]),
            'DETAILS' => implode('<br>', $lines),
            'TYPE' => 'ERROR',
            'HTML' => true,
        ]);
    }
    if ($result->chains !== []) {
        echo BeginNote();
        echo Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_CHAINS') . '<br>';
        foreach ($result->chains as $item) {
            echo Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_LINE', [
                '#LINE#' => $item['line'],
                '#TEXT#' => htmlspecialcharsbx(implode(' > ', $item['urls'])),
            ]) . '<br>';
        }
        echo EndNote();
    }
}
?>
<form method="post" enctype="multipart/form-data" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID) ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr class="adm-detail-required-field">
        <td width="40%"><label for="CSV_FILE"><?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_FILE') ?>:</label></td>
        <td width="60%"><input type="file" name="CSV_FILE" id="CSV_FILE" accept=".csv,text/csv,text/plain"></td>
    </tr>
    <?php if (count($sites) > 1): ?>
        <tr>
            <td><label for="SITE_ID"><?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_SITE') ?>:</label></td>
            <td>
                <select name="SITE_ID" id="SITE_ID">
                    <?php foreach ($sites as $lid => $name): ?>
                        <option value="<?= htmlspecialcharsbx($lid) ?>"<?= $lid === $siteId ? ' selected' : '' ?>>[<?= htmlspecialcharsbx($lid) ?>] <?= htmlspecialcharsbx($name) ?></option>
                    <?php endforeach ?>
                </select>
            </td>
        </tr>
    <?php else: ?>
        <input type="hidden" name="SITE_ID" value="<?= htmlspecialcharsbx($siteId) ?>">
    <?php endif ?>
    <tr>
        <td><label for="STATUS"><?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_STATUS') ?>:</label></td>
        <td>
            <select name="STATUS" id="STATUS">
                <?php foreach (RuleTable::STATUSES as $code): ?>
                    <option value="<?= $code ?>"<?= $code === $status ? ' selected' : '' ?>><?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_STATUS_' . $code) ?></option>
                <?php endforeach ?>
            </select>
        </td>
    </tr>
    <tr>
        <td class="adm-detail-valign-top"><?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_FORMAT') ?>:</td>
        <td>
            <?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_FORMAT_HINT') ?>
            <pre>Откуда;Куда;Код
https://old-site.ru/about-us.html;/about/
/catalog/old-chairs/;/catalog/chairs/;301
/actions/*;/sale/*;302</pre>
        </td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="import" class="adm-btn-save" value="<?= Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_SUBMIT') ?>"<?= $canEdit ? '' : ' disabled' ?>>
    <?php $tabControl->End(); ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
