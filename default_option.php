<?php

// настройки по умолчанию, их отдает Option::get, пока в настройках модуля ничего не сохраняли
$roman140176_redirects_default_option = [
    'log_enabled' => 'Y',
    'log_limit' => '10000',
    'log_days' => '30',
    'log_ignore' => "/wp-*\n/wordpress*\n/xmlrpc.php\n/.env*\n/.git*\n/phpmyadmin*\n*.bak\n*.sql",
];
