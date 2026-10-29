<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Tests\Rule;

use PHPUnit\Framework\TestCase;
use Roman140176\Redirects\Rule\Matcher;

final class MatcherTest extends TestCase
{
    public function testExactRuleIgnoresCaseAndSlash(): void
    {
        $matcher = $this->matcher(['/old/' => '/new/']);

        foreach (['/old/', '/old', '/OLD/'] as $uri) {
            self::assertSame('/new/', $matcher->match($uri)?->url, $uri);
        }
        self::assertNull($matcher->match('/old/more/'));
        self::assertNull($matcher->match('/older/'));
    }

    public function testRedirectKnowsRuleAndStatus(): void
    {
        $matcher = Matcher::fromRules([['ID' => 7, 'SOURCE' => '/old/', 'TARGET' => '/new/', 'STATUS' => 302]]);
        $redirect = $matcher->match('/old/');

        self::assertSame(7, $redirect?->ruleId);
        self::assertSame(302, $redirect?->status);
    }

    public function testQueryGoesToNewAddress(): void
    {
        self::assertSame('/new/?utm_source=ya', $this->matcher(['/old/' => '/new/'])->match('/old/?utm_source=ya')?->url);
        self::assertSame('/new/?a=1&utm_source=ya', $this->matcher(['/old/' => '/new/?a=1'])->match('/old/?utm_source=ya')?->url);
    }

    public function testRuleWithParamsCatchesThemInAnyOrderAndKeepsTheRest(): void
    {
        $matcher = $this->matcher(['/page.php?id=5' => '/item/5/']);

        self::assertSame('/item/5/', $matcher->match('/page.php?id=5')?->url);
        self::assertSame('/item/5/?utm_source=ya', $matcher->match('/page.php?utm_source=ya&id=5')?->url);
        self::assertNull($matcher->match('/page.php?id=6'));
        self::assertNull($matcher->match('/page.php'));
    }

    public function testRuleWithMoreParamsWins(): void
    {
        $matcher = $this->matcher([
            '/page.php?id=5' => '/item/5/',
            '/page.php?id=5&p=2' => '/item/5/page-2/',
        ]);

        self::assertSame('/item/5/page-2/', $matcher->match('/page.php?p=2&id=5')?->url);
        self::assertSame('/item/5/?p=3', $matcher->match('/page.php?id=5&p=3')?->url);
    }

    public function testMaskCarriesTail(): void
    {
        $matcher = $this->matcher(['/catalog/*' => '/shop/*']);

        self::assertSame('/shop/chairs/red/', $matcher->match('/catalog/chairs/red/')?->url);
        self::assertSame('/shop/', $matcher->match('/catalog')?->url);
        self::assertSame('/shop/', $matcher->match('/catalog/')?->url);
        self::assertSame('/shop/Chairs/?sort=price', $matcher->match('/CATALOG/Chairs/?sort=price')?->url);
        self::assertNull($matcher->match('/catalogue/'));
    }

    public function testMaskWithoutStarInTargetDropsTail(): void
    {
        self::assertSame('/news/', $this->matcher(['/blog/*' => '/news/'])->match('/blog/a/b')?->url);
    }

    public function testLongerMaskWins(): void
    {
        $matcher = $this->matcher(['/a/*' => '/x/', '/a/b/*' => '/y/']);

        self::assertSame('/y/', $matcher->match('/a/b/c')?->url);
        self::assertSame('/x/', $matcher->match('/a/c')?->url);
    }

    public function testExactRuleBeatsMask(): void
    {
        $matcher = $this->matcher(['/a/*' => '/x/', '/a/b/' => '/exact/']);

        self::assertSame('/exact/', $matcher->match('/a/b')?->url);
    }

    public function testCyrillicAddressInAnyEncoding(): void
    {
        $matcher = $this->matcher(['/Старый-раздел/' => '/новый/']);
        $encoded = '/%D1%81%D1%82%D0%B0%D1%80%D1%8B%D0%B9-%D1%80%D0%B0%D0%B7%D0%B4%D0%B5%D0%BB/';

        self::assertSame('/%D0%BD%D0%BE%D0%B2%D1%8B%D0%B9/', $matcher->match($encoded)?->url);
        self::assertSame('/%D0%BD%D0%BE%D0%B2%D1%8B%D0%B9/', $matcher->match('/старый-раздел')?->url);
    }

    public function testCyrillicTailIsEncoded(): void
    {
        $matcher = $this->matcher(['/catalog/*' => 'https://new.ru/*']);

        self::assertSame('https://new.ru/%D1%81%D1%82%D1%83%D0%BB%D1%8C%D1%8F/', $matcher->match('/catalog/%D1%81%D1%82%D1%83%D0%BB%D1%8C%D1%8F/')?->url);
    }

    public function testRootRuleAndRootMask(): void
    {
        self::assertSame('/home/', $this->matcher(['/' => '/home/'])->match('/')?->url);
        self::assertNull($this->matcher(['/' => '/home/'])->match('/a/'));
        self::assertSame('https://new.ru/a/b', $this->matcher(['/*' => 'https://new.ru/*'])->match('/a/b')?->url);
    }

    public function testIndexFromCacheWorksTheSame(): void
    {
        $matcher = $this->matcher([
            '/old/' => '/new/',
            '/page.php?id=5' => '/item/5/',
            '/catalog/*' => '/shop/*',
        ]);
        $restored = Matcher::fromArray($matcher->toArray());

        self::assertSame('/new/', $restored->match('/old')?->url);
        self::assertSame('/item/5/', $restored->match('/page.php?id=5')?->url);
        self::assertSame('/shop/a/', $restored->match('/catalog/a/')?->url);
    }

    public function testNoRulesNoRedirect(): void
    {
        self::assertNull(Matcher::fromRules([])->match('/old/'));
    }

    /**
     * @param array<string, string> $rules откуда => куда
     */
    private function matcher(array $rules): Matcher
    {
        $list = [];
        $id = 1;
        foreach ($rules as $source => $target) {
            $list[] = ['ID' => $id++, 'SOURCE' => (string)$source, 'TARGET' => $target, 'STATUS' => 301];
        }

        return Matcher::fromRules($list);
    }
}
