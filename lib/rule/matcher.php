<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Ищет правило для адреса. Сначала правила с параметрами, потом точные, потом маски от длинной к короткой.
 * Индекс собирается один раз и кладется в кеш: точные адреса ищутся по ключу, маски перебираются по порядку
 */
final class Matcher
{
    // насколько точно подошло правило, по тому же порядку, что и поиск
    private const WEIGHT_QUERY = 3000000;
    private const WEIGHT_EXACT = 2000000;
    private const WEIGHT_MASK = 1000000;

    /**
     * @param array{exact: array, query: array, masks: array} $index
     */
    private function __construct(private array $index)
    {
    }

    /**
     * @param list<array{ID: int, SOURCE: string, TARGET: string, STATUS: int}> $rules
     */
    public static function fromRules(array $rules): self
    {
        $index = ['exact' => [], 'query' => [], 'masks' => []];
        foreach ($rules as $rule) {
            self::insert($index, $rule);
        }

        // чем больше параметров совпало, тем точнее правило
        foreach ($index['query'] as &$items) {
            usort($items, static fn (array $a, array $b): int => count($b['pairs']) <=> count($a['pairs']));
        }
        unset($items);
        usort($index['masks'], static fn (array $a, array $b): int => mb_strlen($b['prefix']) <=> mb_strlen($a['prefix']));

        return new self($index);
    }

    public static function fromArray(array $index): self
    {
        return new self($index);
    }

    public function toArray(): array
    {
        return $this->index;
    }

    // добавляет правило на место, без пересборки индекса. Импорт так добавляет тысячи правил по одному
    public function add(array $rule): void
    {
        $place = self::insert($this->index, $rule);

        if ($place === 'masks') {
            usort($this->index['masks'], static fn (array $a, array $b): int => mb_strlen($b['prefix']) <=> mb_strlen($a['prefix']));
        } elseif ($place !== 'exact') {
            usort($this->index['query'][$place], static fn (array $a, array $b): int => count($b['pairs']) <=> count($a['pairs']));
        }
    }

    public function withRule(array $rule): self
    {
        $copy = clone $this;
        $copy->add($rule);

        return $copy;
    }

    // $requestUri как в REQUEST_URI: путь может быть в %-кодировке, параметры как есть
    public function match(string $requestUri): ?Redirect
    {
        $found = $this->find($requestUri);

        return $found !== null ? $this->redirect(...$found['args']) : null;
    }

    // поиск сразу в двух индексах, как будто они один: побеждает правило точнее, при равенстве этот индекс.
    // Так проверяется новое правило, без копии большого индекса
    public function matchWith(self $extra, string $requestUri): ?Redirect
    {
        $found = $this->find($requestUri);
        $other = $extra->find($requestUri);
        if ($other !== null && ($found === null || $other['weight'] > $found['weight'])) {
            return $extra->redirect(...$other['args']);
        }

        return $found !== null ? $this->redirect(...$found['args']) : null;
    }

    /**
     * @return array{weight: int, args: array}|null
     */
    private function find(string $requestUri): ?array
    {
        [$path, $query] = Url::split($requestUri);
        $key = Url::pathKey($path);
        $pairs = Url::pairs($query);

        foreach ($this->index['query'][$key] ?? [] as $item) {
            $rest = $pairs;
            foreach ($item['pairs'] as $needed) {
                $found = array_search($needed, array_map([Url::class, 'normalizePair'], $rest), true);
                if ($found === false) {
                    continue 2;
                }
                array_splice($rest, $found, 1);
            }

            // параметры из правила уже учтены, остальные (метки и т.п.) везем дальше
            return ['weight' => self::WEIGHT_QUERY + count($item['pairs']), 'args' => [$item, '', $rest]];
        }

        if (isset($this->index['exact'][$key])) {
            return ['weight' => self::WEIGHT_EXACT, 'args' => [$this->index['exact'][$key], '', $pairs]];
        }

        $decoded = rawurldecode($path);
        $lower = mb_strtolower($decoded);
        foreach ($this->index['masks'] as $item) {
            $weight = self::WEIGHT_MASK + mb_strlen($item['prefix']);
            if (str_starts_with($lower, $item['prefix'])) {
                // хвост берем из адреса как есть, регистр важен для нового сайта. % ? и # в нем снова кодируем
                return ['weight' => $weight, 'args' => [$item, Url::escape(mb_substr($decoded, mb_strlen($item['prefix']))), $pairs]];
            }
            // /catalog подходит под /catalog/*
            if ($lower . '/' === $item['prefix']) {
                return ['weight' => $weight, 'args' => [$item, '', $pairs]];
            }
        }

        return null;
    }

    /**
     * @return string куда легло правило: exact, masks или ключ пути для правил с параметрами
     */
    private static function insert(array &$index, array $rule): string
    {
        $source = (string)$rule['SOURCE'];
        $item = ['id' => (int)$rule['ID'], 'target' => (string)$rule['TARGET'], 'status' => (int)$rule['STATUS']];

        if (str_ends_with($source, '*')) {
            $item['prefix'] = mb_strtolower(rawurldecode(substr($source, 0, -1)));
            $index['masks'][] = $item;

            return 'masks';
        }

        [$path, $query] = Url::split($source);
        $key = Url::pathKey($path);
        $pairs = array_map([Url::class, 'normalizePair'], Url::pairs($query));
        if ($pairs === []) {
            $index['exact'][$key] = $item;

            return 'exact';
        }

        $item['pairs'] = $pairs;
        $index['query'][$key][] = $item;

        return $key;
    }

    /**
     * @param string[] $pairs параметры, которые надо дописать к новому адресу
     */
    private function redirect(array $item, string $tail, array $pairs): Redirect
    {
        $url = str_replace('*', $tail, $item['target']);
        if ($pairs !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $pairs);
        }

        return new Redirect($item['id'], Url::encode($url), $item['status']);
    }
}
