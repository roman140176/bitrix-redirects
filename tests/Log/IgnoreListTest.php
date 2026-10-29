<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Tests\Log;

use PHPUnit\Framework\TestCase;
use Roman140176\Redirects\Log\IgnoreList;

final class IgnoreListTest extends TestCase
{
    public function testMasksWithStar(): void
    {
        $list = new IgnoreList("/wp-*\n*.sql\n/xmlrpc.php");

        self::assertTrue($list->has('/wp-admin/'));
        self::assertTrue($list->has('/WP-LOGIN.php'));
        self::assertTrue($list->has('/backup.sql'));
        self::assertTrue($list->has('/old/dump.SQL'));
        self::assertTrue($list->has('/xmlrpc.php'));
        self::assertFalse($list->has('/catalog/'));
        self::assertFalse($list->has('/xmlrpc.php5'));
        self::assertFalse($list->has('/blog/wp-admin/'));
    }

    public function testEmptyLinesAndSpacesDoNotMatchEverything(): void
    {
        $list = new IgnoreList("\r\n  /wp-*  \r\n\r\n   \n");

        self::assertTrue($list->has('/wp-admin/'));
        self::assertFalse($list->has('/catalog/'));
        self::assertFalse($list->has(''));
    }

    public function testDotsAndBracketsAreJustText(): void
    {
        $list = new IgnoreList("/a.b\n/(x)*");

        self::assertTrue($list->has('/a.b'));
        self::assertFalse($list->has('/aXb'));
        self::assertTrue($list->has('/(x)/y'));
    }

    public function testCyrillicMask(): void
    {
        self::assertTrue((new IgnoreList('/Тест*'))->has('/тестовая/'));
    }

    public function testNoMasksNothingIgnored(): void
    {
        self::assertFalse((new IgnoreList(''))->has('/wp-admin/'));
    }
}
