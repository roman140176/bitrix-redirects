<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Путь посетителя по правилам: откуда пришел и куда его поведут дальше
 */
final class Chain
{
    /**
     * @param string[] $urls адрес из правила, затем все адреса, куда поведут
     */
    public function __construct(
        public readonly bool $loop,
        public readonly array $urls,
    ) {
    }

    // больше одного редиректа подряд: лучше сразу вести на последний адрес
    public function isChain(): bool
    {
        return !$this->loop && count($this->urls) > 2;
    }
}
