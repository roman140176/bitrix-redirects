<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Import;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Localization\Loc;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\Model\RuleTable;
use Roman140176\Redirects\Rule\ChainChecker;
use Roman140176\Redirects\Rule\Url;

/**
 * Импорт правил из CSV. Дубли и петли ловятся так же, как в форме, только проверяются по списку в памяти:
 * проверка через базу на каждой строке на тысячах строк шла бы минуты
 */
final class Importer
{
    public function import(string $content, string $siteId, int $defaultStatus): ImportResult
    {
        $data = (new CsvReader())->read($content);

        $errors = [];
        foreach ($data['errors'] as $error) {
            $errors[] = ['line' => $error['line'], 'message' => Loc::getMessage('ROMAN140176_REDIRECTS_IMPORT_' . $error['code'])];
        }

        $checker = new ChainChecker(RuleTable::activeRules($siteId), RuleTable::siteHosts($siteId));
        // ключи всех правил сайта, и выключенных тоже: дубль это дубль
        $keys = array_flip(array_column(
            RuleTable::getList(['select' => ['SOURCE_KEY'], 'filter' => ['=SITE_ID' => $siteId]])->fetchAll(),
            'SOURCE_KEY'
        ));
        // номер строки => новое правило, нужно для цепочек
        $added = [];

        // одна транзакция на весь файл: фиксировать каждую строку отдельно в разы дольше.
        // Ошибка одной строки транзакцию не ломает, MySQL откатывает только эту вставку
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $this->addRows($data['rows'], $siteId, $defaultStatus, $checker, $keys, $added, $errors);
            $connection->commitTransaction();
        } catch (\Throwable $e) {
            $connection->rollbackTransaction();
            throw $e;
        }
        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $chains = [];
        if ($added !== []) {
            NotFoundLog::forgetMatched($siteId);

            foreach ($added as $line => $rule) {
                $chain = $checker->follow($rule);
                if ($chain->isChain()) {
                    $chains[] = ['line' => $line, 'urls' => $chain->urls];
                }
            }
        }

        return new ImportResult(count($added), $errors, $chains);
    }

    private function addRows(array $rows, string $siteId, int $defaultStatus, ChainChecker $checker, array &$keys, array &$added, array &$errors): void
    {
        RuleTable::bulk(function () use ($rows, $siteId, $defaultStatus, $checker, &$keys, &$added, &$errors): void {
            foreach ($rows as $row) {
                $rule = [
                    'ID' => 0,
                    'SOURCE' => Url::fromInput($row['source']),
                    'TARGET' => $row['target'],
                    'STATUS' => $row['status'] ?? $defaultStatus,
                ];

                // кривые адреса и код отклонят валидаторы RuleTable, тут проверяем только то, что они не видят
                if (Url::isSource($rule['SOURCE']) && Url::isTarget($rule['TARGET'])) {
                    $key = Url::key($rule['SOURCE']);
                    if (isset($keys[$key])) {
                        $errors[] = ['line' => $row['line'], 'message' => RuleTable::duplicateMessage($rule['SOURCE'])];
                        continue;
                    }
                    $chain = $checker->follow($rule);
                    if ($chain->loop) {
                        $errors[] = ['line' => $row['line'], 'message' => RuleTable::loopMessage($chain)];
                        continue;
                    }
                }

                try {
                    $result = RuleTable::add(['SITE_ID' => $siteId, 'ACTIVE' => true] + array_diff_key($rule, ['ID' => 0]));
                } catch (SqlQueryException) {
                    // такое же правило только что добавили в другой вкладке, уникальный ключ не пустил
                    $errors[] = ['line' => $row['line'], 'message' => RuleTable::duplicateMessage($rule['SOURCE'])];
                    continue;
                }
                if (!$result->isSuccess()) {
                    $errors[] = ['line' => $row['line'], 'message' => implode('; ', $result->getErrorMessages())];
                    continue;
                }

                $rule['ID'] = (int)$result->getId();
                $keys[Url::key($rule['SOURCE'])] = true;
                $checker->add($rule);
                $added[$row['line']] = $rule;
            }
        });
    }
}
