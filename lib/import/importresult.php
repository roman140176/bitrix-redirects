<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Import;

/**
 * Итог импорта: сколько добавлено, что не получилось и какие правила попали в цепочки
 */
final class ImportResult
{
    /**
     * @param list<array{line: int, message: string}> $errors
     * @param list<array{line: int, urls: string[]}> $chains
     */
    public function __construct(
        public readonly int $added,
        public readonly array $errors,
        public readonly array $chains,
    ) {
    }
}
