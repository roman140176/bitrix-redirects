<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Tests\Rule;

use PHPUnit\Framework\TestCase;
use Roman140176\Redirects\Rule\ChainChecker;

final class ChainCheckerTest extends TestCase
{
    public function testChainOfTwoRules(): void
    {
        $rules = [$this->rule(1, '/a/', '/b/'), $this->rule(2, '/b/', '/c/')];
        $chain = ChainChecker::check($rules, $rules[0]);

        self::assertFalse($chain->loop);
        self::assertTrue($chain->isChain());
        self::assertSame(['/a/', '/b/', '/c/'], $chain->urls);
    }

    public function testSingleRuleIsNotChain(): void
    {
        $rules = [$this->rule(1, '/a/', '/b/'), $this->rule(2, '/x/', '/y/')];
        $chain = ChainChecker::check($rules, $rules[0]);

        self::assertFalse($chain->loop);
        self::assertFalse($chain->isChain());
        self::assertSame(['/a/', '/b/'], $chain->urls);
    }

    public function testNewRuleBackIsLoop(): void
    {
        $chain = ChainChecker::check([$this->rule(1, '/a/', '/b/')], $this->rule(0, '/b/', '/a/'));

        self::assertTrue($chain->loop);
        self::assertFalse($chain->isChain());
    }

    public function testRuleToItselfIsLoop(): void
    {
        self::assertTrue(ChainChecker::check([], $this->rule(0, '/a', '/A/'))->loop);
    }

    public function testMaskIntoItselfIsLoop(): void
    {
        self::assertTrue(ChainChecker::check([], $this->rule(0, '/*', '/new/*'))->loop);
    }

    public function testExternalTargetIsNotFollowed(): void
    {
        $chain = ChainChecker::check([], $this->rule(0, '/*', 'https://new.ru/*'));

        self::assertFalse($chain->loop);
        self::assertFalse($chain->isChain());
    }

    public function testEditedRuleReplacesOldVersion(): void
    {
        // было B => C, правим на B => A, а A => B уже есть
        $rules = [$this->rule(1, '/a/', '/b/'), $this->rule(2, '/b/', '/c/')];

        self::assertTrue(ChainChecker::check($rules, $this->rule(2, '/b/', '/a/'))->loop);
    }

    public function testChainThroughMask(): void
    {
        $rules = [$this->rule(1, '/old/', '/catalog/chairs/'), $this->rule(2, '/catalog/*', '/shop/*')];
        $chain = ChainChecker::check($rules, $rules[0]);

        self::assertSame(['/old/', '/catalog/chairs/', '/shop/chairs/'], $chain->urls);
    }

    public function testCyrillicChainIsShownReadable(): void
    {
        $rules = [$this->rule(1, '/a/', '/старый/'), $this->rule(2, '/старый/', '/новый/')];

        self::assertSame(['/a/', '/старый/', '/новый/'], ChainChecker::check($rules, $rules[0])->urls);
    }

    public function testTenRedirectsAreChainElevenAreLoop(): void
    {
        $rules = [];
        for ($i = 1; $i <= 11; $i++) {
            $rules[] = $this->rule($i, "/$i/", '/' . ($i + 1) . '/');
        }

        self::assertTrue(ChainChecker::check($rules, $rules[0])->loop);
        self::assertTrue(ChainChecker::check($rules, $rules[1])->isChain());
    }

    public function testMaskLoopThroughTailOfAnotherRule(): void
    {
        // /a/x/ => /b/x/ по маске, а /b/x/ уже ведет обратно на /a/x/
        $rules = [$this->rule(1, '/b/x/', '/a/x/')];

        self::assertTrue(ChainChecker::check($rules, $this->rule(0, '/a/*', '/b/*'))->loop);
    }

    public function testMaskWithoutLoopStaysAllowed(): void
    {
        $rules = [$this->rule(1, '/b/x/', '/c/'), $this->rule(2, '/a/x/*', '/x/')];

        self::assertFalse(ChainChecker::check($rules, $this->rule(0, '/a/*', '/b/*'))->loop);
    }

    public function testOwnDomainIsFollowed(): void
    {
        $rules = [$this->rule(1, '/a/', 'https://site.ru/b/')];
        $back = $this->rule(0, '/b/', 'https://www.SITE.ru:443/a/');

        self::assertTrue(ChainChecker::check($rules, $back, ['site.ru'])->loop);
        self::assertFalse(ChainChecker::check($rules, $back, ['other.ru'])->loop);
    }

    public function testChainThroughOwnDomainIsShown(): void
    {
        $rules = [$this->rule(1, '/a/', 'https://site.ru/b/'), $this->rule(2, '/b/', '/c/')];

        self::assertSame(['/a/', 'https://site.ru/b/', '/c/'], ChainChecker::check($rules, $rules[0], ['www.site.ru'])->urls);
    }

    public function testCheckerKeepsRulesAddedDuringImport(): void
    {
        $checker = new ChainChecker([]);
        self::assertFalse($checker->follow($this->rule(0, '/a/', '/b/'))->loop);
        $checker->add($this->rule(1, '/a/', '/b/'));

        self::assertTrue($checker->follow($this->rule(0, '/b/', '/a/'))->loop);
        self::assertSame(['/c/', '/a/', '/b/'], $checker->follow($this->rule(0, '/c/', '/a/'))->urls);
    }

    private function rule(int $id, string $source, string $target): array
    {
        return ['ID' => $id, 'SOURCE' => $source, 'TARGET' => $target, 'STATUS' => 301];
    }
}
