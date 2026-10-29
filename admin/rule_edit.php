<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\Model\EO_Rule;
use Roman140176\Redirects\Model\RuleTable;
use Roman140176\Redirects\Rule\Chain;
use Roman140176\Redirects\Rule\ChainChecker;
use Roman140176\Redirects\Rule\Url;

/** @global CMain $APPLICATION */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'roman140176.redirects';
$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' || !Loader::includeModule($moduleId)) {
    $APPLICATION->AuthForm(Loc::getMessage('ROMAN140176_REDIRECTS_ACCESS_DENIED'));
}
$canEdit = $right >= 'W';

$request = Application::getInstance()->getContext()->getRequest();
$id = (int)$request->get('ID');
$listUrl = 'roman140176_redirects_rules.php?lang=' . LANGUAGE_ID;
$errors = [];

$tabControl = new CAdminTabControl('roman140176_redirects_rule_edit', [
    ['DIV' => 'rule', 'TAB' => Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_TAB'), 'TITLE' => Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_TAB_TITLE')],
]);

$sites = array_column(SiteTable::getList(['select' => ['LID', 'NAME'], 'order' => ['SORT' => 'ASC']])->fetchAll(), 'NAME', 'LID');

// куда в итоге попадет посетитель по сохраненному правилу
$ruleChain = static fn (EO_Rule $rule): Chain => ChainChecker::check(RuleTable::activeRules($rule->getSiteId()), [
    'ID' => $rule->getId(),
    'SOURCE' => $rule->getSource(),
    'TARGET' => $rule->getTarget(),
    'STATUS' => $rule->getStatus(),
], RuleTable::siteHosts($rule->getSiteId()));

if ($id > 0) {
    $rule = RuleTable::getByPrimary($id)->fetchObject();
} else {
    // кнопка "Сделать редирект" в журнале 404 открывает форму с адресом и сайтом
    $rule = RuleTable::createObject();
    $rule->setSource(Url::fromInput((string)$request->get('SOURCE')));
    $rule->setSiteId(isset($sites[(string)$request->get('SITE_ID')]) ? (string)$request->get('SITE_ID') : (string)array_key_first($sites));
}

if ($rule !== null && $id > 0 && $request->get('action') === 'delete') {
    if ($canEdit && check_bitrix_sessid()) {
        $result = RuleTable::delete($id);
        if ($result->isSuccess()) {
            LocalRedirect($listUrl);
        }
        $errors = $result->getErrorMessages();
    } else {
        $errors[] = Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_NO_RIGHTS');
    }
}

if ($rule !== null && $request->isPost() && ($request->getPost('save') !== null || $request->getPost('apply') !== null)) {
    if (!$canEdit || !check_bitrix_sessid()) {
        $errors[] = Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_NO_RIGHTS');
    } else {
        $rule->setSiteId((string)$request->getPost('SITE_ID'));
        $rule->setActive($request->getPost('ACTIVE') === 'Y');
        // полный адрес старого сайта можно вставить как есть, домен отрежем
        $rule->setSource(Url::fromInput((string)$request->getPost('SOURCE')));
        $rule->setTarget(trim((string)$request->getPost('TARGET')));
        $rule->setStatus((int)$request->getPost('STATUS'));

        $result = $rule->save();
        if ($result->isSuccess()) {
            NotFoundLog::forgetMatched($rule->getSiteId());
            // если правило попало в цепочку, остаемся в форме: там предупреждение, куда лучше вести
            $stay = $request->getPost('apply') !== null || ($rule->getActive() && $ruleChain($rule)->isChain());
            LocalRedirect($stay
                ? 'roman140176_redirects_rule_edit.php?ID=' . $rule->getId() . '&lang=' . LANGUAGE_ID . '&' . $tabControl->ActiveTabParam()
                : $listUrl);
        }
        $errors = $result->getErrorMessages();
    }
}

if ($rule === null) {
    $title = Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_NOT_FOUND');
} elseif ($id > 0) {
    $title = Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_TITLE', ['#ID#' => $id]);
} else {
    $title = Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_TITLE_NEW');
}
$APPLICATION->SetTitle($title);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$menu = [
    ['TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_BACK'), 'LINK' => $listUrl, 'ICON' => 'btn_list'],
];
if ($canEdit && $rule !== null && $id > 0) {
    $deleteUrl = 'roman140176_redirects_rule_edit.php?ID=' . $id . '&action=delete&lang=' . LANGUAGE_ID . '&' . bitrix_sessid_get();
    $menu[] = ['TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_ADD'), 'LINK' => 'roman140176_redirects_rule_edit.php?lang=' . LANGUAGE_ID, 'ICON' => 'btn_new'];
    $menu[] = [
        'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_DELETE'),
        'LINK' => "javascript:if (confirm('" . GetMessageJS('ROMAN140176_REDIRECTS_EDIT_DELETE_CONFIRM') . "')) window.location = '" . CUtil::JSEscape($deleteUrl) . "';",
        'ICON' => 'btn_delete',
    ];
}
(new CAdminContextMenu($menu))->Show();

if ($errors !== []) {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_ERROR'),
        'DETAILS' => implode('<br>', array_map('htmlspecialcharsbx', $errors)),
        'TYPE' => 'ERROR',
        'HTML' => true,
    ]);
}

if ($rule === null) {
    CAdminMessage::ShowMessage(Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_NOT_FOUND'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

    return;
}

// сохраненное правило проверяем на цепочку: лишний редирект работает, но лучше вести сразу на последний адрес
if ($id > 0 && $errors === [] && $rule->getActive()) {
    $chain = $ruleChain($rule);
    if ($chain->isChain()) {
        echo BeginNote();
        echo Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_CHAIN', [
            '#CHAIN#' => htmlspecialcharsbx(implode(' > ', $chain->urls)),
            '#LAST#' => htmlspecialcharsbx($chain->urls[array_key_last($chain->urls)]),
        ]);
        echo EndNote();
    }
}

$value = static fn ($text): string => htmlspecialcharsbx((string)$text);
?>
<form method="post" action="<?= $value($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . ($id > 0 ? '&ID=' . $id : '')) ?>" name="roman140176_redirects_rule_form">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="ID" value="<?= $id ?>">
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php if ($id > 0): ?>
        <tr>
            <td width="40%">ID:</td>
            <td width="60%"><?= $id ?></td>
        </tr>
        <tr>
            <td><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_HITS') ?>:</td>
            <td><?= (int)$rule->getHits() ?><?= $rule->getLastHitAt() !== null ? ', ' . Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_LAST_HIT_AT') . ' ' . $value($rule->getLastHitAt()) : '' ?></td>
        </tr>
    <?php endif ?>
    <?php if (count($sites) > 1): ?>
        <tr>
            <td width="40%"><label for="SITE_ID"><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_SITE') ?>:</label></td>
            <td width="60%">
                <select name="SITE_ID" id="SITE_ID">
                    <?php foreach ($sites as $lid => $name): ?>
                        <option value="<?= $value($lid) ?>"<?= $lid === $rule->getSiteId() ? ' selected' : '' ?>>[<?= $value($lid) ?>] <?= $value($name) ?></option>
                    <?php endforeach ?>
                </select>
            </td>
        </tr>
    <?php else: ?>
        <input type="hidden" name="SITE_ID" value="<?= $value($rule->getSiteId()) ?>">
    <?php endif ?>
    <tr>
        <td width="40%"><label for="ACTIVE"><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_ACTIVE') ?>:</label></td>
        <td width="60%"><input type="checkbox" name="ACTIVE" id="ACTIVE" value="Y"<?= $rule->getActive() !== false ? ' checked' : '' ?>></td>
    </tr>
    <tr class="adm-detail-required-field">
        <td class="adm-detail-valign-top"><label for="SOURCE"><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_SOURCE') ?>:</label></td>
        <td>
            <input type="text" name="SOURCE" id="SOURCE" size="60" maxlength="255" value="<?= $value($rule->getSource()) ?>"><br>
            <small><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_SOURCE_HINT') ?></small>
        </td>
    </tr>
    <tr class="adm-detail-required-field">
        <td class="adm-detail-valign-top"><label for="TARGET"><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_TARGET') ?>:</label></td>
        <td>
            <input type="text" name="TARGET" id="TARGET" size="60" maxlength="500" value="<?= $value($rule->getTarget()) ?>"><br>
            <small><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_TARGET_HINT') ?></small>
        </td>
    </tr>
    <tr>
        <td><label for="STATUS"><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_STATUS') ?>:</label></td>
        <td>
            <select name="STATUS" id="STATUS">
                <?php foreach (RuleTable::STATUSES as $status): ?>
                    <option value="<?= $status ?>"<?= $status === (int)($rule->getStatus() ?: 301) ? ' selected' : '' ?>><?= Loc::getMessage('ROMAN140176_REDIRECTS_EDIT_STATUS_' . $status) ?></option>
                <?php endforeach ?>
            </select>
        </td>
    </tr>
    <?php
    $tabControl->Buttons($canEdit
        ? ['back_url' => $listUrl]
        : ['btnSave' => false, 'btnApply' => false, 'back_url' => $listUrl]);
    $tabControl->End();
    ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
