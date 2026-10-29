<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Проверяет, куда в итоге попадет посетитель по правилу: не ходит ли по кругу и нет ли лишних редиректов
 */
final class ChainChecker
{
    // больше 10 редиректов подряд браузер все равно не пройдет, считаем это петлей
    public const MAX_STEPS = 10;

    /**
     * @param list<array{ID: int, SOURCE: string, TARGET: string, STATUS: int}> $rules активные правила сайта
     * @param array{ID: int, SOURCE: string, TARGET: string, STATUS: int} $rule проверяемое правило, у нового ID 0
     */
    public static function check(array $rules, array $rule): Chain
    {
        $id = (int)$rule['ID'];
        // старую версию правила подменяем новой
        $rules = array_filter($rules, static fn (array $item): bool => (int)$item['ID'] !== $id);
        $rules[] = $rule;
        $matcher = Matcher::fromRules(array_values($rules));

        // у маски хвост неизвестен, проверяем с пустым
        $url = str_replace('*', '', (string)$rule['TARGET']);
        $urls = [(string)$rule['SOURCE'], $url];
        $visited = [$id => true];

        // на другой сайт не идем, там свои правила
        while (!preg_match('#^https?://#i', $url)) {
            $redirect = $matcher->match($url);
            if ($redirect === null) {
                break;
            }
            if (isset($visited[$redirect->ruleId]) || count($visited) >= self::MAX_STEPS) {
                return new Chain(true, $urls);
            }

            $visited[$redirect->ruleId] = true;
            $url = rawurldecode($redirect->url);
            $urls[] = $url;
        }

        return new Chain(false, $urls);
    }
}
