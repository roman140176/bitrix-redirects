<?php

declare(strict_types=1);

namespace Roman140176\Redirects;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Roman140176\Redirects\Log\IgnoreList;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\Model\RuleTable;
use Roman140176\Redirects\Rule\Redirect;
use Roman140176\Redirects\Rule\Url;

/**
 * Обработчики событий ядра. Регистрирует их установщик модуля
 */
final class EventHandler
{
    private const MODULE_ID = 'roman140176.redirects';
    private const STATUS_LINES = [
        301 => '301 Moved Permanently',
        302 => '302 Found',
    ];

    // самое начало хита: если для адреса есть правило, сразу уводим
    public static function onPageStart(): void
    {
        try {
            $redirect = self::findRedirect();
            if ($redirect === null) {
                return;
            }
            RuleTable::registerHit($redirect->ruleId);
        } catch (\Throwable) {
            // модуль не должен ронять сайт: без редиректа страница откроется как обычно
            return;
        }

        LocalRedirect($redirect->url, true, self::STATUS_LINES[$redirect->status] ?? self::STATUS_LINES[301]);
    }

    // конец страницы: если это 404, записываем адрес в журнал
    public static function onEpilog(): void
    {
        try {
            self::logNotFound();
        } catch (\Throwable) {
            // журнал не важнее страницы
        }
    }

    private static function findRedirect(): ?Redirect
    {
        $context = Context::getCurrent();
        // формы и HEAD/GET отдельно: POST после 301 браузер превратит в GET, данные формы пропадут
        if (!self::isSitePage() || !in_array($context->getRequest()->getRequestMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $siteId = self::siteId();

        return $siteId !== '' ? RuleCache::matcher($siteId)->match(self::requestUri()) : null;
    }

    private static function logNotFound(): void
    {
        if (!self::isSitePage() || Option::get(self::MODULE_ID, 'log_enabled') !== 'Y') {
            return;
        }

        // 404 ставят по-разному: старый 404.php и компоненты через ERROR_404 и CHTTP, новый код через ответ D7
        $notFound = (defined('ERROR_404') && ERROR_404 === 'Y')
            || str_starts_with((string)\CHTTP::GetLastStatus(), '404')
            || (int)Context::getCurrent()->getResponse()->getStatus() === 404;
        $siteId = self::siteId();
        if (!$notFound || $siteId === '') {
            return;
        }

        $uri = self::requestUri();
        $ignore = new IgnoreList(Option::get(self::MODULE_ID, 'log_ignore'));
        if ($ignore->has(rawurldecode(Url::split($uri)[0]))) {
            return;
        }

        NotFoundLog::add($siteId, $uri, (string)Context::getCurrent()->getServer()->get('HTTP_REFERER'));
    }

    // консоль, админку и служебные адреса /bitrix/ не трогаем, даже если правило подходит
    private static function isSitePage(): bool
    {
        if (PHP_SAPI === 'cli' || (defined('ADMIN_SECTION') && ADMIN_SECTION === true)) {
            return false;
        }

        return !str_starts_with(Url::pathKey(Url::split(self::requestUri())[0]) . '/', '/bitrix/');
    }

    private static function requestUri(): string
    {
        return (string)Context::getCurrent()->getServer()->getRequestUri();
    }

    private static function siteId(): string
    {
        $siteId = (string)Context::getCurrent()->getSite();
        if ($siteId === '' && defined('SITE_ID')) {
            $siteId = SITE_ID;
        }

        return $siteId;
    }
}
