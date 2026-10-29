<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Rule;

/**
 * Разбор адресов для правил: проверка, ключ для сравнения, кодирование для заголовка Location
 */
final class Url
{
    public const SOURCE_MAX_LENGTH = 255;
    public const TARGET_MAX_LENGTH = 500;

    // полный адрес старого сайта превращаем в путь: https://old.ru/a/?x=1 => /a/?x=1
    public static function fromInput(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://[^/?\#]*(.*)$#i', $value, $matches)) {
            // у адреса без пути (https://old.ru или https://old.ru?x=1) путь это главная
            $value = str_starts_with($matches[1], '/') ? $matches[1] : '/' . $matches[1];
        }

        // якорь до сервера не доходит, в правиле он не нужен
        return explode('#', $value, 2)[0];
    }

    // откуда: путь с /, звездочка только одна и только в конце, маска без параметров
    public static function isSource(string $source): bool
    {
        if ($source === '' || $source[0] !== '/' || strlen($source) > self::SOURCE_MAX_LENGTH || !self::isClean($source)) {
            return false;
        }

        $stars = substr_count($source, '*');

        return $stars === 0 || ($stars === 1 && str_ends_with($source, '*') && !str_contains($source, '?'));
    }

    // куда: путь на этом сайте или полный адрес http(s)
    public static function isTarget(string $target): bool
    {
        if (strlen($target) > self::TARGET_MAX_LENGTH || !self::isClean($target) || substr_count($target, '*') > 1) {
            return false;
        }

        return (bool)preg_match('#^(/(?!/)|https?://[^/?\#]+)#i', $target);
    }

    // по ключу правила сравниваются между собой: без регистра, без хвостового слеша, параметры по алфавиту
    public static function key(string $source): string
    {
        if (str_ends_with($source, '*')) {
            return mb_strtolower(rawurldecode($source));
        }

        [$path, $query] = self::split($source);
        $pairs = array_map([self::class, 'normalizePair'], self::pairs($query));
        sort($pairs);

        return self::pathKey($path) . ($pairs !== [] ? '?' . implode('&', $pairs) : '');
    }

    // заголовок Location должен быть в ASCII, кириллицу и пробелы кодируем
    public static function encode(string $url): string
    {
        return preg_replace_callback('/[^\x21-\x7E]/', static fn (array $m): string => rawurlencode($m[0]), $url);
    }

    /**
     * @return array{0: string, 1: string} путь и параметры без ?
     */
    public static function split(string $uri): array
    {
        $uri = explode('#', $uri, 2)[0];
        $parts = explode('?', $uri, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    public static function pathKey(string $path): string
    {
        $path = rtrim(mb_strtolower(rawurldecode($path)), '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * @return string[] пары как пришли, пустые выкинуты
     */
    public static function pairs(string $query): array
    {
        return array_values(array_filter(explode('&', $query), static fn (string $pair): bool => $pair !== ''));
    }

    public static function normalizePair(string $pair): string
    {
        return mb_strtolower(urldecode($pair));
    }

    // без пробелов и битой кодировки
    private static function isClean(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !preg_match('/\s/u', $value);
    }
}
