<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Куда отправить посетителя и по какому правилу
 */
final class Redirect
{
    public function __construct(
        public readonly int $ruleId,
        public readonly string $url,
        public readonly int $status,
    ) {
    }
}
