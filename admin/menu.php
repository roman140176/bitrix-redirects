<?php

use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */

if ($APPLICATION->GetGroupRight('roman140176.redirects') < 'R') {
    return false;
}

return [
    'parent_menu' => 'global_menu_services',
    'section' => 'roman140176_redirects',
    'sort' => 500,
    'text' => Loc::getMessage('ROMAN140176_REDIRECTS_MENU'),
    'title' => Loc::getMessage('ROMAN140176_REDIRECTS_MENU_TITLE'),
    'icon' => 'default_menu_icon',
    'page_icon' => 'default_page_icon',
    'items_id' => 'menu_roman140176_redirects',
    'items' => [
        [
            'text' => Loc::getMessage('ROMAN140176_REDIRECTS_MENU_RULES'),
            'url' => 'roman140176_redirects_rules.php?lang=' . LANGUAGE_ID,
            'more_url' => ['roman140176_redirects_rule_edit.php'],
        ],
        [
            'text' => Loc::getMessage('ROMAN140176_REDIRECTS_MENU_LOG'),
            'url' => 'roman140176_redirects_log.php?lang=' . LANGUAGE_ID,
        ],
        [
            'text' => Loc::getMessage('ROMAN140176_REDIRECTS_MENU_IMPORT'),
            'url' => 'roman140176_redirects_import.php?lang=' . LANGUAGE_ID,
        ],
    ],
];
