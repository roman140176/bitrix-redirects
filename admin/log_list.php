<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;
use Bitrix\Main\UI\Filter\Options;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\Model\NotFoundTable;

/** @global CMain $APPLICATION */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'roman140176.redirects';
$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' || !Loader::includeModule($moduleId)) {
    $APPLICATION->AuthForm(Loc::getMessage('ROMAN140176_REDIRECTS_ACCESS_DENIED'));
}
$canEdit = $right >= 'W';

$request = Application::getInstance()->getContext()->getRequest();
$pageUrl = 'roman140176_redirects_log.php?lang=' . LANGUAGE_ID;

// очистить весь журнал, кнопка вверху списка
if ($request->get('action') === 'clear' && $canEdit && check_bitrix_sessid()) {
    Application::getConnection()->truncateTable(NotFoundTable::getTableName());
    LocalRedirect($pageUrl);
}

$tableId = 'tbl_roman140176_redirects_log';
$sorting = new CAdminUiSorting($tableId, 'HITS', 'desc');
$list = new CAdminUiList($tableId, $sorting);

$sites = array_column(SiteTable::getList(['select' => ['LID', 'NAME'], 'order' => ['SORT' => 'ASC']])->fetchAll(), 'NAME', 'LID');
$filterFields = [
    ['id' => 'SITE_ID', 'name' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_SITE'), 'type' => 'list', 'items' => $sites, 'default' => count($sites) > 1],
];

$ids = $canEdit ? $list->GroupAction() : false;
if (is_array($ids) && $list->GetAction() === 'delete') {
    foreach ($ids as $id) {
        if ((int)$id > 0) {
            NotFoundTable::delete((int)$id);
        }
    }
}

$filter = (new Options($tableId))->getFilter($filterFields);
$query = NotFoundTable::query()->setSelect(['*']);
$search = trim((string)($filter['FIND'] ?? ''));
if ($search !== '') {
    $query->whereLike('URL', '%' . $search . '%');
}
if (isset($filter['SITE_ID'])) {
    $query->where('SITE_ID', (string)$filter['SITE_ID']);
}

global $by, $order;
$sortBy = strtoupper((string)$by);
if (!in_array($sortBy, ['ID', 'SITE_ID', 'URL', 'HITS', 'FIRST_AT', 'LAST_AT'], true)) {
    $sortBy = 'HITS';
}
$query->setOrder([$sortBy => strtoupper((string)$order) === 'ASC' ? 'ASC' : 'DESC', 'ID' => 'DESC']);

if ($list->isTotalCountRequest()) {
    $query->countTotal(true);
}
$nav = $list->getPageNavigation('nav-roman140176-redirects-log');
$query->setOffset($nav->getOffset());
$query->setLimit($nav->getLimit() + 1);

$result = $query->exec();
if ($list->isTotalCountRequest()) {
    $list->sendTotalCountResponse($result->getCount());
}

$list->AddHeaders([
    ['id' => 'URL', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_URL'), 'sort' => 'URL', 'default' => true],
    ['id' => 'HITS', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_HITS'), 'sort' => 'HITS', 'default' => true],
    ['id' => 'LAST_AT', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_LAST_AT'), 'sort' => 'LAST_AT', 'default' => true],
    ['id' => 'FIRST_AT', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_FIRST_AT'), 'sort' => 'FIRST_AT', 'default' => true],
    ['id' => 'REFERER', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_REFERER'), 'default' => true],
    ['id' => 'SITE_ID', 'content' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_SITE'), 'sort' => 'SITE_ID', 'default' => count($sites) > 1],
    ['id' => 'MAKE', 'content' => '', 'default' => $canEdit],
]);

$pageSize = $list->getNavSize();
$n = 0;
while ($item = $result->fetch()) {
    $n++;
    if ($n > $pageSize) {
        break;
    }

    $id = (int)$item['ID'];
    // форма правила откроется уже с этим адресом
    $makeUrl = 'roman140176_redirects_rule_edit.php?lang=' . LANGUAGE_ID . '&SITE_ID=' . urlencode($item['SITE_ID']) . '&SOURCE=' . urlencode($item['URL']);

    $row = $list->AddRow($id, ['ID' => $id], $canEdit ? $makeUrl : false, Loc::getMessage('ROMAN140176_REDIRECTS_LOG_MAKE'));
    $row->AddViewField('URL', htmlspecialcharsbx($item['URL']));
    $row->AddViewField('HITS', (string)(int)$item['HITS']);
    $row->AddViewField('LAST_AT', htmlspecialcharsbx((string)$item['LAST_AT']));
    $row->AddViewField('FIRST_AT', htmlspecialcharsbx((string)$item['FIRST_AT']));
    // ссылку не делаем кликабельной: referer присылает кто угодно
    $row->AddViewField('REFERER', htmlspecialcharsbx((string)$item['REFERER']));
    $row->AddViewField('SITE_ID', htmlspecialcharsbx($item['SITE_ID']));

    if ($canEdit) {
        $row->AddViewField('MAKE', '<a href="' . htmlspecialcharsbx($makeUrl) . '">' . Loc::getMessage('ROMAN140176_REDIRECTS_LOG_MAKE') . '</a>');
        $row->AddActions([
            ['ICON' => 'edit', 'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_MAKE'), 'LINK' => $makeUrl, 'DEFAULT' => true],
            [
                'ICON' => 'delete',
                'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_DELETE'),
                'ACTION' => $list->ActionDoGroup($id, 'delete'),
            ],
        ]);
    }
}

$nav->setRecordCount($nav->getOffset() + $n);
$list->setNavigation($nav, Loc::getMessage('ROMAN140176_REDIRECTS_LOG_NAV'), false);

if ($canEdit) {
    $list->AddGroupActionTable(['delete' => true]);
}

$context = [];
if ($canEdit) {
    $clearUrl = $pageUrl . '&action=clear&' . bitrix_sessid_get();
    $context[] = [
        'TEXT' => Loc::getMessage('ROMAN140176_REDIRECTS_LOG_CLEAR'),
        'LINK' => "javascript:if (confirm('" . GetMessageJS('ROMAN140176_REDIRECTS_LOG_CLEAR_CONFIRM') . "')) window.location = '" . CUtil::JSEscape($clearUrl) . "';",
        'ICON' => 'btn_delete',
    ];
}
$list->AddAdminContextMenu($context, false);

$list->CheckListMode();

$APPLICATION->SetTitle(Loc::getMessage('ROMAN140176_REDIRECTS_LOG_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (Option::get($moduleId, 'log_enabled') !== 'Y') {
    echo BeginNote();
    echo Loc::getMessage('ROMAN140176_REDIRECTS_LOG_DISABLED', [
        '#URL#' => htmlspecialcharsbx('settings.php?mid=' . urlencode($moduleId) . '&lang=' . LANGUAGE_ID),
    ]);
    echo EndNote();
} elseif (NotFoundLog::isFull()) {
    // иначе журнал молча перестанет пополняться, пока агент не почистит старое
    echo BeginNote();
    echo Loc::getMessage('ROMAN140176_REDIRECTS_LOG_FULL', [
        '#LIMIT#' => (int)Option::get($moduleId, 'log_limit'),
        '#URL#' => htmlspecialcharsbx('settings.php?mid=' . urlencode($moduleId) . '&lang=' . LANGUAGE_ID),
    ]);
    echo EndNote();
}

$list->DisplayFilter($filterFields);
$list->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
