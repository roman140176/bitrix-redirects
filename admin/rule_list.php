<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SiteTable;
use Bitrix\Main\UI\Filter\Options;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\Model\RuleTable;

/** @global CMain $APPLICATION */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'roman140176.redirects';
$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' || !Loader::includeModule($moduleId)) {
    $APPLICATION->AuthForm(Loc::getMessage('ROMAN140176_REDIRECTS_ACCESS_DENIED'));
}
$canEdit = $right >= 'W';

$tableId = 'tbl_roman140176_redirects_rules';
$sorting = new CAdminUiSorting($tableId, 'ID', 'desc');
$list = new CAdminUiList($tableId, $sorting);

$sites = array_column(SiteTable::getList(['select' => ['LID', 'NAME'], 'order' => ['SORT' => 'ASC']])->fetchAll(), 'NAME', 'LID');
$yesNo = ['Y' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_YES'), 'N' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_NO')];

$filterFields = [
    ['id' => 'SITE_ID', 'name' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_SITE'), 'type' => 'list', 'items' => $sites, 'default' => count($sites) > 1],
    ['id' => 'ACTIVE', 'name' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_ACTIVE'), 'type' => 'list', 'items' => $yesNo, 'default' => true],
];

// групповые действия и удаление из строки, только с правом на запись
$ids = $canEdit ? $list->GroupAction() : false;
if (is_array($ids)) {
    $touchedSites = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }

        $result = match ($list->GetAction()) {
            'delete' => RuleTable::delete($id),
            'activate' => RuleTable::update($id, ['ACTIVE' => true]),
            'deactivate' => RuleTable::update($id, ['ACTIVE' => false]),
            default => null,
        };
        if ($result !== null && !$result->isSuccess()) {
            $list->AddGroupError(implode('; ', $result->getErrorMessages()), $id);
        } elseif ($list->GetAction() === 'activate') {
            $touchedSites[] = (string)RuleTable::getByPrimary($id, ['select' => ['SITE_ID']])->fetch()['SITE_ID'];
        }
    }
    // включенные правила закрывают адреса из журнала 404
    foreach (array_unique($touchedSites) as $siteId) {
        NotFoundLog::forgetMatched($siteId);
    }
}

$filter = (new Options($tableId))->getFilter($filterFields);
$query = RuleTable::query()->setSelect(['*']);

$search = trim((string)($filter['FIND'] ?? ''));
if ($search !== '') {
    $query->where(Query::filter()
        ->logic('or')
        ->whereLike('SOURCE', '%' . $search . '%')
        ->whereLike('TARGET', '%' . $search . '%'));
}
if (isset($filter['SITE_ID'])) {
    $query->where('SITE_ID', (string)$filter['SITE_ID']);
}
if (isset($filter['ACTIVE'])) {
    $query->where('ACTIVE', $filter['ACTIVE'] === 'Y');
}

// сортировку $by и $order кладет CAdminUiSorting, как у списков ядра
global $by, $order;
$sortBy = strtoupper((string)$by);
if (!in_array($sortBy, ['ID', 'ACTIVE', 'SITE_ID', 'SOURCE', 'TARGET', 'STATUS', 'HITS', 'LAST_HIT_AT', 'CREATED_AT'], true)) {
    $sortBy = 'ID';
}
$query->setOrder([$sortBy => strtoupper((string)$order) === 'ASC' ? 'ASC' : 'DESC', 'ID' => 'DESC']);

if ($list->isTotalCountRequest()) {
    $query->countTotal(true);
}
$nav = $list->getPageNavigation('nav-roman140176-redirects-rules');
$query->setOffset($nav->getOffset());
$query->setLimit($nav->getLimit() + 1);

$result = $query->exec();
if ($list->isTotalCountRequest()) {
    $list->sendTotalCountResponse($result->getCount());
}

$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'ACTIVE', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_ACTIVE'), 'sort' => 'ACTIVE', 'default' => true],
    ['id' => 'SITE_ID', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_SITE'), 'sort' => 'SITE_ID', 'default' => count($sites) > 1],
    ['id' => 'SOURCE', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_SOURCE'), 'sort' => 'SOURCE', 'default' => true],
    ['id' => 'TARGET', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_TARGET'), 'sort' => 'TARGET', 'default' => true],
    ['id' => 'STATUS', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_STATUS'), 'sort' => 'STATUS', 'default' => true],
    ['id' => 'HITS', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_HITS'), 'sort' => 'HITS', 'default' => true],
    ['id' => 'LAST_HIT_AT', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_LAST_HIT_AT'), 'sort' => 'LAST_HIT_AT', 'default' => true],
    ['id' => 'CREATED_AT', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_CREATED_AT'), 'sort' => 'CREATED_AT', 'default' => false],
]);

$pageSize = $list->getNavSize();
$n = 0;
while ($rule = $result->fetch()) {
    $n++;
    if ($n > $pageSize) {
        break;
    }

    $id = (int)$rule['ID'];
    $editUrl = 'roman140176_redirects_rule_edit.php?ID=' . $id . '&lang=' . LANGUAGE_ID;
    $active = $rule['ACTIVE'] === true || $rule['ACTIVE'] === 'Y';

    $row = $list->AddRow($id, ['ID' => $id], $editUrl, Loc::getMessage('ROMAN140176_REDIRECTS_RULES_EDIT'));
    $row->AddViewField('ACTIVE', $active ? $yesNo['Y'] : $yesNo['N']);
    $row->AddViewField('SITE_ID', htmlspecialcharsbx($rule['SITE_ID']));
    $row->AddViewField('SOURCE', '<a href="' . htmlspecialcharsbx($editUrl) . '">' . htmlspecialcharsbx($rule['SOURCE']) . '</a>');
    $row->AddViewField('TARGET', htmlspecialcharsbx($rule['TARGET']));
    $row->AddViewField('STATUS', (string)(int)$rule['STATUS']);
    $row->AddViewField('HITS', (string)(int)$rule['HITS']);
    $row->AddViewField('LAST_HIT_AT', htmlspecialcharsbx((string)$rule['LAST_HIT_AT']));
    $row->AddViewField('CREATED_AT', htmlspecialcharsbx((string)$rule['CREATED_AT']));

    $actions = [
        ['ICON' => 'edit', 'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_EDIT'), 'LINK' => $editUrl, 'DEFAULT' => true],
    ];
    if ($canEdit) {
        $actions[] = [
            'ICON' => 'delete',
            'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_DELETE'),
            'ACTION' => "if (confirm('" . GetMessageJS('ROMAN140176_REDIRECTS_RULES_DELETE_CONFIRM') . "')) " . $list->ActionDoGroup($id, 'delete'),
        ];
    }
    $row->AddActions($actions);
}

$nav->setRecordCount($nav->getOffset() + $n);
$list->setNavigation($nav, Loc::getMessage('ROMAN140176_REDIRECTS_RULES_NAV'), false);

if ($canEdit) {
    $list->AddGroupActionTable([
        'delete' => true,
        'activate' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_ACTIVATE'),
        'deactivate' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_DEACTIVATE'),
    ]);
}

$context = [];
if ($canEdit) {
    $context[] = [
        'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_RULES_ADD'),
        'LINK' => 'roman140176_redirects_rule_edit.php?lang=' . LANGUAGE_ID,
        'ICON' => 'btn_new',
    ];
}
$list->AddAdminContextMenu($context, false);

$list->CheckListMode();

$APPLICATION->SetTitle(Loc::getMessage('ROMAN140176_REDIRECTS_RULES_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$list->DisplayFilter($filterFields);
$list->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
