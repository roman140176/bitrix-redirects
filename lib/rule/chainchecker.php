<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Проверяет, куда в итоге попадет посетитель по правилу: не ходит ли по кругу и нет ли лишних редиректов.
 * Импорт держит один экземпляр и добавляет в него правила по одному, форма зовет check()
 */
final class ChainChecker
{
    // Google идет не больше чем по 10 редиректам подряд, цепочку длиннее считаем петлей
    public const MAX_STEPS = 10;

    private Matcher $matcher;
    /** @var string[] откуда у всех правил, нужно для проверки масок */
    private array $sources = [];
    /** @var string[] домены сайта без www и порта */
    private array $hosts;

    /**
     * @param list<array{ID: int, SOURCE: string, TARGET: string, STATUS: int}> $rules активные правила сайта
     * @param string[] $hosts домены сайта: полный адрес на них это тот же сайт, по нему тоже идем
     */
    public function __construct(array $rules, array $hosts = [])
    {
        $this->matcher = Matcher::fromRules($rules);
        $this->sources = array_map(static fn (array $rule): string => (string)$rule['SOURCE'], $rules);
        $this->hosts = array_map([self::class, 'host'], $hosts);
    }

    /**
     * @param array{ID: int, SOURCE: string, TARGET: string, STATUS: int} $rule проверяемое правило, у нового ID 0.
     *        Его старая версия в $rules заменяется
     */
    public static function check(array $rules, array $rule, array $hosts = []): Chain
    {
        $others = array_filter($rules, static fn (array $item): bool => (int)$item['ID'] !== (int)$rule['ID']);

        return (new self(array_values($others), $hosts))->follow($rule);
    }

    public function add(array $rule): void
    {
        $this->matcher->add($rule);
        $this->sources[] = (string)$rule['SOURCE'];
    }

    // правило еще не добавлено: проверяем, что будет, если добавить
    public function follow(array $rule): Chain
    {
        $id = (int)$rule['ID'];
        $source = (string)$rule['SOURCE'];
        $target = (string)$rule['TARGET'];
        // проверяемое правило в своем маленьком индексе, большой не копируем
        $single = Matcher::fromRules([$rule]);
        $match = fn (string $url): ?Redirect => $this->matcher->matchWith($single, $url);

        // у маски хвост неизвестен. Основной путь с пустым хвостом, он же показывается как цепочка
        $chain = $this->walk($match, $id, str_replace('*', '', $target));
        if ($chain->loop) {
            return new Chain(true, [$source, ...$chain->urls]);
        }

        // маска может замкнуться через чужое правило: /a/* на /b/* при /b/x/ на /a/x/.
        // Пробуем хвосты, при которых маска приводит ровно на "откуда" другого правила
        $prefix = str_ends_with($source, '*') ? substr($source, 0, -1) : null;
        $targetPrefix = $this->local(strstr($target, '*', true) ?: '');
        if ($prefix !== null && $targetPrefix !== null && str_contains($target, '*')) {
            $targetPrefix = rawurldecode($targetPrefix);
            foreach ($this->sources as $other) {
                $other = rawurldecode(rtrim(Url::split($other)[0], '*'));
                if (!str_starts_with(mb_strtolower($other), mb_strtolower($targetPrefix))) {
                    continue;
                }
                // адрес, с которого маска приведет на $other. Если его ловит другое правило, маска тут ни при чем
                $start = $prefix . Url::escape(mb_substr($other, mb_strlen($targetPrefix)));
                if ($match($start)?->ruleId !== $id) {
                    continue;
                }
                $tail = $this->walk($match, $id, Url::escape($other));
                if ($tail->loop) {
                    return new Chain(true, [$start, ...$tail->urls]);
                }
            }
        }

        return new Chain(false, [$source, ...$chain->urls]);
    }

    // идем по правилам от адреса $url, пока не уйдем с сайта, не кончатся правила или не вернемся в $startId
    private function walk(\Closure $match, int $startId, string $url): Chain
    {
        $urls = [$url];
        $visited = [$startId => true];

        while (($path = $this->local($url)) !== null) {
            $redirect = $match($path);
            if ($redirect === null) {
                break;
            }
            if (isset($visited[$redirect->ruleId]) || count($visited) >= self::MAX_STEPS) {
                return new Chain(true, $urls);
            }

            $visited[$redirect->ruleId] = true;
            $url = $redirect->url;
            // в цепочке для людей кириллица читаемая
            $urls[] = rawurldecode($url);
        }

        return new Chain(false, $urls);
    }

    // путь на этом сайте или null, если адрес ведет на другой сайт
    private function local(string $url): ?string
    {
        if (str_starts_with($url, '/')) {
            return $url;
        }
        if (preg_match('#^https?://([^/?\#]+)(.*)$#i', $url, $m) && in_array(self::host($m[1]), $this->hosts, true)) {
            return str_starts_with($m[2], '/') ? $m[2] : '/' . $m[2];
        }

        return null;
    }

    private static function host(string $host): string
    {
        $host = strtolower(explode(':', trim($host), 2)[0]);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
