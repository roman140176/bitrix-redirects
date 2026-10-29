<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Log;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Roman140176\Redirects\Model\NotFoundTable;
use Roman140176\Redirects\Rule\Url;
use Roman140176\Redirects\RuleCache;

/**
 * Запись в журнал 404 и его чистка. Один адрес это одна строка со счетчиком, поэтому журнал не пухнет от повторов
 */
final class NotFoundLog
{
    private const MODULE_ID = 'roman140176.redirects';
    private const MAX_LENGTH = 500;

    public static function add(string $siteId, string $uri, string $referer): void
    {
        [$path, $query] = Url::split($uri);
        // кириллицу храним читаемой, а битую кодировку от сканеров оставляем как пришла.
        // Метки рекламы выкидываем: у Директа и Google они свои на каждый клик и дробили бы адрес на тысячи строк
        $readable = Url::readable($path);
        $query = Url::withoutTracking($query);
        $url = (mb_check_encoding($readable, 'UTF-8') ? $readable : $path) . ($query !== '' ? '?' . $query : '');
        $url = mb_substr(mb_scrub($url, 'UTF-8'), 0, self::MAX_LENGTH);
        $referer = mb_substr(mb_scrub($referer, 'UTF-8'), 0, self::MAX_LENGTH);

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $table = NotFoundTable::getTableName();
        $now = $helper->convertToDbDateTime(new DateTime());
        $where = sprintf("SITE_ID = '%s' AND URL_HASH = '%s'", $helper->forSql($siteId), md5(mb_strtolower($url)));

        // сначала пробуем посчитать уже известный адрес, так на частых 404 нет лишних запросов
        $connection->queryExecute(
            "UPDATE $table SET HITS = HITS + 1, LAST_AT = $now"
            . ($referer !== '' ? ", REFERER = '" . $helper->forSql($referer) . "'" : '')
            . " WHERE $where"
        );
        if ($connection->getAffectedRowsCount() > 0) {
            return;
        }

        // новый адрес. Если журнал забит, новые не пишем, пока агент или человек его не почистит.
        // Строк не больше лимита, так что COUNT тут дешевый
        $limit = (int)Option::get(self::MODULE_ID, 'log_limit');
        if ((int)$connection->queryScalar("SELECT COUNT(*) FROM $table") >= $limit) {
            return;
        }

        // IGNORE на случай, если тот же адрес только что записал соседний хит
        $connection->queryExecute(sprintf(
            "INSERT IGNORE INTO $table (SITE_ID, URL, URL_HASH, REFERER, HITS, FIRST_AT, LAST_AT) VALUES ('%s', '%s', '%s', %s, 1, %s, %s)",
            $helper->forSql($siteId),
            $helper->forSql($url),
            md5(mb_strtolower($url)),
            $referer !== '' ? "'" . $helper->forSql($referer) . "'" : 'NULL',
            $now,
            $now
        ));
    }

    public static function isFull(): bool
    {
        $count = (int)Application::getConnection()->queryScalar('SELECT COUNT(*) FROM ' . NotFoundTable::getTableName());

        return $count >= (int)Option::get(self::MODULE_ID, 'log_limit');
    }

    // убирает из журнала адреса, на которые уже есть правило. Зовут после сохранения правил
    public static function forgetMatched(string $siteId): int
    {
        $matcher = RuleCache::matcher($siteId);
        $ids = [];
        $rows = NotFoundTable::query()->setSelect(['ID', 'URL'])->where('SITE_ID', $siteId)->fetchAll();
        foreach ($rows as $row) {
            if ($matcher->match((string)$row['URL']) !== null) {
                $ids[] = (int)$row['ID'];
            }
        }

        $connection = Application::getConnection();
        foreach (array_chunk($ids, 500) as $chunk) {
            $connection->queryExecute('DELETE FROM ' . NotFoundTable::getTableName() . ' WHERE ID IN (' . implode(',', $chunk) . ')');
        }

        return count($ids);
    }

    // агент раз в сутки: удаляет адреса, которые давно никто не открывал
    public static function cleanup(): string
    {
        $days = max(1, (int)Option::get(self::MODULE_ID, 'log_days'));
        $connection = Application::getConnection();
        $border = $connection->getSqlHelper()->convertToDbDateTime(DateTime::createFromTimestamp(time() - $days * 86400));
        $connection->queryExecute('DELETE FROM ' . NotFoundTable::getTableName() . ' WHERE LAST_AT < ' . $border);

        return '\\' . self::class . '::cleanup();';
    }
}
