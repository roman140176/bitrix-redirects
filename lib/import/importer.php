<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Import;

use Bitrix\Main\Localization\Loc;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\Model\RuleTable;
use Roman140176\Redirects\Rule\ChainChecker;
use Roman140176\Redirects\Rule\Url;

/**
 * Импорт правил из CSV. Каждая строка сохраняется через RuleTable, так что дубли и петли ловятся как в форме
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

        // номер строки => ID нового правила, нужен для цепочек
        $added = [];
        foreach ($data['rows'] as $row) {
            $result = RuleTable::add([
                'SITE_ID' => $siteId,
                'ACTIVE' => true,
                'SOURCE' => Url::fromInput($row['source']),
                'TARGET' => $row['target'],
                'STATUS' => $row['status'] ?? $defaultStatus,
            ]);
            if ($result->isSuccess()) {
                $added[$row['line']] = (int)$result->getId();
            } else {
                $errors[] = ['line' => $row['line'], 'message' => implode('; ', $result->getErrorMessages())];
            }
        }
        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $chains = [];
        if ($added !== []) {
            NotFoundLog::forgetMatched($siteId);

            $rules = RuleTable::activeRules($siteId);
            $byId = array_column($rules, null, 'ID');
            foreach ($added as $line => $id) {
                $chain = ChainChecker::check($rules, $byId[$id]);
                if ($chain->isChain()) {
                    $chains[] = ['line' => $line, 'urls' => $chain->urls];
                }
            }
        }

        return new ImportResult(count($added), $errors, $chains);
    }
}
