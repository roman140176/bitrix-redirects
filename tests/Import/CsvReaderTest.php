<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Tests\Import;

use PHPUnit\Framework\TestCase;
use Roman140176\Redirects\Import\CsvReader;

final class CsvReaderTest extends TestCase
{
    public function testSemicolonWithAndWithoutStatus(): void
    {
        $data = (new CsvReader())->read("/a/;/b/\n/c/;/d/;302\n");

        self::assertSame([
            ['line' => 1, 'source' => '/a/', 'target' => '/b/', 'status' => null],
            ['line' => 2, 'source' => '/c/', 'target' => '/d/', 'status' => 302],
        ], $data['rows']);
        self::assertSame([], $data['errors']);
    }

    public function testCommaAndTab(): void
    {
        self::assertSame('/b/', (new CsvReader())->read("/a/,/b/")['rows'][0]['target']);
        self::assertSame('/b/', (new CsvReader())->read("/a/\t/b/")['rows'][0]['target']);
    }

    public function testCommaInsideAddressWithSemicolonFile(): void
    {
        self::assertSame('/a/?ids=1,2', (new CsvReader())->read("/a/?ids=1,2;/b/")['rows'][0]['source']);
    }

    public function testQuotedCell(): void
    {
        self::assertSame('/a/?x=1;2', (new CsvReader())->read("\"/a/?x=1;2\";/b/")['rows'][0]['source']);
    }

    public function testHeaderIsSkippedButLineNumbersStay(): void
    {
        $data = (new CsvReader())->read("Откуда;Куда;Код\r\n/a/;/b/;301\r\n");

        self::assertCount(1, $data['rows']);
        self::assertSame(2, $data['rows'][0]['line']);
    }

    public function testFullAddressInFirstLineIsNotHeader(): void
    {
        $data = (new CsvReader())->read("https://old.ru/a/;/b/");

        self::assertSame('https://old.ru/a/', $data['rows'][0]['source']);
    }

    public function testEmptyLinesAreSkippedAndSpacesTrimmed(): void
    {
        $data = (new CsvReader())->read("\n /a/ ; /b/ \n\n;;\n/c/;/d/");

        self::assertSame([2, 5], array_column($data['rows'], 'line'));
        self::assertSame(['/a/', '/b/'], [$data['rows'][0]['source'], $data['rows'][0]['target']]);
        self::assertSame([], $data['errors']);
    }

    public function testOneColumnIsError(): void
    {
        $data = (new CsvReader())->read("/a/;/b/\n/c/\n/e/;\n");

        self::assertSame([['line' => 2, 'code' => 'COLUMNS'], ['line' => 3, 'code' => 'COLUMNS']], $data['errors']);
        self::assertCount(1, $data['rows']);
    }

    public function testStatusMustBeNumber(): void
    {
        $data = (new CsvReader())->read("/a/;/b/;abc");

        self::assertSame([['line' => 1, 'code' => 'STATUS']], $data['errors']);
        self::assertSame([], $data['rows']);
    }

    public function testWindows1251FromExcel(): void
    {
        $content = mb_convert_encoding("/старый/;/новый/", 'Windows-1251', 'UTF-8');
        $row = (new CsvReader())->read($content)['rows'][0];

        self::assertSame('/старый/', $row['source']);
        self::assertSame('/новый/', $row['target']);
    }

    public function testBomIsDropped(): void
    {
        $data = (new CsvReader())->read("\xEF\xBB\xBF/a/;/b/");

        self::assertSame('/a/', $data['rows'][0]['source']);
        self::assertSame(1, $data['rows'][0]['line']);
    }
}
