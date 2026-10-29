<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Tests\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Roman140176\Redirects\Rule\Url;

final class UrlTest extends TestCase
{
    public function testFullAddressBecomesPath(): void
    {
        self::assertSame('/a/?x=1', Url::fromInput(' https://old.ru/a/?x=1 '));
        self::assertSame('/', Url::fromInput('http://old.ru'));
        self::assertSame('/?x=1', Url::fromInput('http://old.ru?x=1'));
    }

    public function testEmptyStaysEmpty(): void
    {
        // пустое поле формы не должно превращаться в правило для главной
        self::assertSame('', Url::fromInput(''));
        self::assertSame('', Url::fromInput('   '));
        self::assertSame('/', Url::fromInput('http://old.ru#top'));
    }

    public function testSpacesInsideAddressAreEncoded(): void
    {
        self::assertSame('/My%20File.pdf', Url::fromInput('https://old.ru/My File.pdf'));
        self::assertSame('/a%20b/', Url::fromInput(' /a b/ '));
    }

    public function testReadableKeepsSpecialCharsEncoded(): void
    {
        // в журнале кириллица читаемая, а пробел, %, # и ? остаются закодированными, иначе правило из такого адреса не сохранить
        self::assertSame('/я/', Url::readable('/%D1%8F/'));
        self::assertSame('/My%20File.pdf', Url::readable('/My%20File.pdf'));
        self::assertSame('/100%25/', Url::readable('/100%25/'));
        self::assertSame('/a%3Fb%23c/', Url::readable('/a%3Fb%23c/'));
    }

    public function testTrackingParamsAreDropped(): void
    {
        self::assertSame('id=5', Url::withoutTracking('utm_source=a&id=5&yclid=1&YSCLID=2&gclid=3&fbclid=4&_openstat=5&UTM_MEDIUM=b'));
        self::assertSame('page=2&sort=price', Url::withoutTracking('page=2&sort=price'));
        self::assertSame('', Url::withoutTracking('utm_source=a'));
        self::assertSame('', Url::withoutTracking(''));
    }

    public function testAnchorIsDropped(): void
    {
        self::assertSame('/a/', Url::fromInput('/a/#top'));
    }

    #[DataProvider('sources')]
    public function testSource(string $source, bool $valid): void
    {
        self::assertSame($valid, Url::isSource($source));
    }

    public static function sources(): array
    {
        return [
            ['/', true],
            ['/a/*', true],
            ['/*', true],
            ['/a/?x=1', true],
            ['/старый/', true],
            ['a/', false],
            ['', false],
            ['/a*b', false],
            ['/a/*?x=1', false],
            ['/a?x=*', false],
            ['/**', false],
            ['/a b/', false],
            ['/' . str_repeat('a', 255), false],
        ];
    }

    #[DataProvider('targets')]
    public function testTarget(string $target, bool $valid): void
    {
        self::assertSame($valid, Url::isTarget($target));
    }

    public static function targets(): array
    {
        return [
            ['/new/', true],
            ['/', true],
            ['https://new.ru/*', true],
            ['http://new.ru', true],
            ['//evil.ru', false],
            ['ftp://x', false],
            ['new', false],
            ['https://', false],
            ['https://new.ru*', false],
            ['https://*.new.ru/', false],
            ['https://new.ru?x=*', true],
            ['/a/*/*', false],
            ['/a b/', false],
        ];
    }

    public function testKeyIgnoresCaseSlashAndParamOrder(): void
    {
        self::assertSame(Url::key('/old?a=1&b=2'), Url::key('/Old/?b=2&a=1'));
        self::assertSame(Url::key('/old'), Url::key('/OLD/'));
        self::assertNotSame(Url::key('/old'), Url::key('/old?a=1'));
        self::assertSame(Url::key('/старый'), Url::key('/%D0%A1%D1%82%D0%B0%D1%80%D1%8B%D0%B9/'));
    }

    public function testMaskKeyKeepsSlash(): void
    {
        // /a/* и /a* ловят разное, ключи тоже разные
        self::assertNotSame(Url::key('/a/*'), Url::key('/a*'));
        self::assertSame(Url::key('/a/*'), Url::key('/A/*'));
    }

    public function testEncodeLeavesAsciiAndEncodesTheRest(): void
    {
        self::assertSame('/%D0%BD%D0%BE%D0%B2%D1%8B%D0%B9/?q=%D1%8F', Url::encode('/новый/?q=я'));
        self::assertSame('https://new.ru/a/?x=1&y=%20', Url::encode('https://new.ru/a/?x=1&y=%20'));
        self::assertSame('/a%20b/', Url::encode('/a b/'));
    }
}
