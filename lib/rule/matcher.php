<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Ищет правило для адреса. Сначала правила с параметрами, потом точные, потом маски от длинной к короткой.
 * Индекс собирается один раз и кладется в кеш, на хите только поиск по ключу
 */
final class Matcher
{
    /**
     * @param array{exact: array, query: array, masks: array} $index
     */
    private function __construct(private readonly array $index)
    {
    }

    /**
     * @param list<array{ID: int, SOURCE: string, TARGET: string, STATUS: int}> $rules
     */
    public static function fromRules(array $rules): self
    {
        $index = ['exact' => [], 'query' => [], 'masks' => []];

        foreach ($rules as $rule) {
            $source = (string)$rule['SOURCE'];
            $item = ['id' => (int)$rule['ID'], 'target' => (string)$rule['TARGET'], 'status' => (int)$rule['STATUS']];

            if (str_ends_with($source, '*')) {
                $item['prefix'] = mb_strtolower(rawurldecode(substr($source, 0, -1)));
                $index['masks'][] = $item;
                continue;
            }

            [$path, $query] = Url::split($source);
            $pairs = array_map([Url::class, 'normalizePair'], Url::pairs($query));
            if ($pairs === []) {
                $index['exact'][Url::pathKey($path)] = $item;
            } else {
                $item['pairs'] = $pairs;
                $index['query'][Url::pathKey($path)][] = $item;
            }
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

    // $requestUri как в REQUEST_URI: путь может быть в %-кодировке, параметры как есть
    public function match(string $requestUri): ?Redirect
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
            return $this->redirect($item, '', $rest);
        }

        if (isset($this->index['exact'][$key])) {
            return $this->redirect($this->index['exact'][$key], '', $pairs);
        }

        $decoded = rawurldecode($path);
        $lower = mb_strtolower($decoded);
        foreach ($this->index['masks'] as $item) {
            if (str_starts_with($lower, $item['prefix'])) {
                // хвост берем из адреса как есть, регистр важен для нового сайта
                return $this->redirect($item, mb_substr($decoded, mb_strlen($item['prefix'])), $pairs);
            }
            // /catalog подходит под /catalog/*
            if ($lower . '/' === $item['prefix']) {
                return $this->redirect($item, '', $pairs);
            }
        }

        return null;
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
