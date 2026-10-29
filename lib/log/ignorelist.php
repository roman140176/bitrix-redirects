<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Log;

/**
 * Адреса, которые не пишем в журнал 404: сканеры ищут /wp-admin, дампы и т.п. Маска на строку, * это любые символы
 */
final class IgnoreList
{
    /** @var string[] */
    private array $patterns = [];

    public function __construct(string $masks)
    {
        foreach (preg_split('/\R/', $masks) as $mask) {
            $mask = trim($mask);
            if ($mask !== '') {
                $this->patterns[] = '#^' . str_replace('\*', '.*', preg_quote(mb_strtolower($mask), '#')) . '$#u';
            }
        }
    }

    // $path уже раскодированный, без параметров
    public function has(string $path): bool
    {
        $path = mb_strtolower($path);
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
