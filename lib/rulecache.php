<?php

declare(strict_types=1);

namespace Roman140176\Redirects;

use Bitrix\Main\Application;
use Roman140176\Redirects\Model\RuleTable;
use Roman140176\Redirects\Rule\Matcher;

/**
 * Правила сайта из кеша. На хите одно чтение кеша, база нужна только после изменения правил
 */
final class RuleCache
{
    private const DIR = 'roman140176_redirects';
    // правила сбрасываются при каждом изменении, срок нужен только чтобы кеш не жил вечно
    private const TTL = 86400;

    public static function matcher(string $siteId): Matcher
    {
        $cache = Application::getInstance()->getManagedCache();
        $id = 'rules_' . $siteId;

        if ($cache->read(self::TTL, $id, self::DIR)) {
            return Matcher::fromArray($cache->get($id));
        }

        $matcher = Matcher::fromRules(RuleTable::activeRules($siteId));
        $cache->set($id, $matcher->toArray());

        return $matcher;
    }

    public static function clear(): void
    {
        Application::getInstance()->getManagedCache()->cleanDir(self::DIR);
    }
}
