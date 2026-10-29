<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Import;

/**
 * Разбор CSV с правилами: откуда, куда и код (необязательно). Адреса тут не проверяются, это дело RuleTable
 */
final class CsvReader
{
    /**
     * @return array{
     *     rows: list<array{line: int, source: string, target: string, status: ?int}>,
     *     errors: list<array{line: int, code: string}>
     * }
     */
    public function read(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        // Excel в русской Windows сохраняет CSV в Windows-1251
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $rows = [];
        $errors = [];
        $delimiter = null;
        $first = true;

        foreach (preg_split('/\R/', $content) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            // разделитель берем по первой строке, у всего файла он один
            $delimiter ??= self::delimiter($line);
            $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            if (implode('', $cells) === '') {
                continue;
            }

            $number = $index + 1;
            // первая строка без адреса в начале это заголовок вроде "Откуда;Куда"
            if ($first) {
                $first = false;
                if (!str_starts_with($cells[0], '/') && !preg_match('#^https?://#i', $cells[0])) {
                    continue;
                }
            }

            $status = $cells[2] ?? '';
            if (($cells[1] ?? '') === '' || $cells[0] === '') {
                $errors[] = ['line' => $number, 'code' => 'COLUMNS'];
            } elseif ($status !== '' && !ctype_digit($status)) {
                $errors[] = ['line' => $number, 'code' => 'STATUS'];
            } else {
                $rows[] = ['line' => $number, 'source' => $cells[0], 'target' => $cells[1], 'status' => $status === '' ? null : (int)$status];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    // чего в строке больше, то и разделитель. При равенстве точка с запятой: запятая бывает внутри адреса
    private static function delimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), ',' => substr_count($line, ',')];
        arsort($counts);

        return (string)array_key_first($counts);
    }
}
