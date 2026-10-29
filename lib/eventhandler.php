<?php

declare(strict_types=1);

namespace Roman140176\Redirects;

use Bitrix\Main\Context;
use Roman140176\Redirects\Model\RuleTable;
use Roman140176\Redirects\Rule\Redirect;
use Roman140176\Redirects\Rule\Url;

/**
 * Обработчики событий ядра. Регистрирует их установщик модуля
 */
final class EventHandler
{
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

    private static function findRedirect(): ?Redirect
    {
        $context = Context::getCurrent();
        // консоль, админка, формы и служебные адреса /bitrix/ не трогаем, даже если правило подходит
        if (PHP_SAPI === 'cli' || (defined('ADMIN_SECTION') && ADMIN_SECTION === true)) {
            return null;
        }
        if (!in_array($context->getRequest()->getRequestMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $uri = (string)$context->getServer()->getRequestUri();
        if (str_starts_with(Url::pathKey(Url::split($uri)[0]) . '/', '/bitrix/')) {
            return null;
        }

        $siteId = (string)$context->getSite();
        if ($siteId === '' && defined('SITE_ID')) {
            $siteId = SITE_ID;
        }

        return $siteId !== '' ? RuleCache::matcher($siteId)->match($uri) : null;
    }
}
