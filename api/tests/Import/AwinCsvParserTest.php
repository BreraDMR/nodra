<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\AwinCsvParser;
use PHPUnit\Framework\TestCase;

final class AwinCsvParserTest extends TestCase
{
    private AwinCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AwinCsvParser();
    }

    public function testParsesTheDocumentedFixture(): void
    {
        $file = $this->parser->parse(AwinFeed::csv());

        self::assertSame(3, $file->totalRows);
        self::assertSame([], $file->errors);
        self::assertCount(3, $file->rows);

        $xt = $file->rows[0];
        self::assertSame(1, $xt->rowNumber);
        self::assertSame('BC-1001', $xt->productId);
        self::assertSame('Shimano XT CS-M8100 cassette 12-speed', $xt->name);
        self::assertSame('Shimano', $xt->brandName);
        self::assertSame(AwinFeed::ean(1), $xt->ean);
        self::assertSame('CSM8100122', $xt->mpn);
        self::assertSame(8990, $xt->priceMinor);
        self::assertSame('EUR', $xt->currency);
        self::assertSame(9990, $xt->rrpMinor);
        self::assertTrue($xt->inStock);
        self::assertSame(7, $xt->quantity);
        self::assertSame('2-4 days', $xt->deliveryTime);
        self::assertSame('Components > Cassettes > 12-speed', $xt->categoryPath);
        self::assertSame('Cassettes', $xt->merchantCategory);
        self::assertSame('https://t.example/BC-1001', $xt->deepLink);
        self::assertSame('2026-09-28 10:00:00', $xt->lastUpdated);
        self::assertSame(['https://img.example/xt.jpg', 'https://img.example/xt_large.jpg', 'https://img.example/xt_alt.jpg'], $xt->images);

        // number_available wins over stock_quantity as the reported quantity
        self::assertSame(2, $file->rows[1]->quantity);
        self::assertSame('Black', $file->rows[1]->colour);
        self::assertNull($file->rows[1]->rrpMinor);
    }

    public function testModelNumberIsTheMpnFallback(): void
    {
        $file = $this->parser->parse(AwinFeed::csv([AwinFeed::row(['mpn' => ''], 9)]));

        self::assertSame([], $file->errors);
        self::assertSame('IMS8100', $file->rows[0]->mpn);
    }

    public function testReadsGzippedFiles(): void
    {
        $file = $this->parser->parse(AwinFeed::gzipped(AwinFeed::csv()));

        self::assertSame([], $file->errors);
        self::assertCount(3, $file->rows);
        self::assertSame('BC-1001', $file->rows[0]->productId);
    }

    public function testStripsTheByteOrderMark(): void
    {
        $file = $this->parser->parse("\xEF\xBB\xBF".AwinFeed::csv([AwinFeed::row([], 1)]));

        self::assertSame([], $file->errors);
        self::assertSame('BC-1001', $file->rows[0]->productId);
    }

    public function testMissingOptionalColumnsReadAsEmpty(): void
    {
        $columns = ['product_id', 'product_name', 'price', 'currency', 'deep_link'];
        $file = $this->parser->parse(AwinFeed::csv([AwinFeed::row()], $columns), );

        self::assertSame([], $file->errors);
        $row = $file->rows[0];
        self::assertNull($row->rrpMinor);
        self::assertNull($row->ean);
        self::assertNull($row->mpn);
        self::assertNull($row->deliveryTime);
        self::assertNull($row->quantity);
        self::assertSame([], $row->images);
        self::assertNull($row->categoryPath);
        self::assertNull($row->merchantCategory);
    }

    public function testMissingRequiredColumnsFailTheWholeFile(): void
    {
        $columns = ['product_id', 'product_name', 'currency', 'deep_link'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('price');
        $this->parser->parse(AwinFeed::csv([AwinFeed::row()], $columns));
    }

    public function testNotACsvOrArchiveIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required column');
        $this->parser->parse('this is not a csv, at all');
    }

    public function testBrokenRowsAreErrorsWhileTheRestContinues(): void
    {
        $csv = AwinFeed::csv([
            AwinFeed::row(['price' => ''], 1),
            AwinFeed::row(['product_name' => ''], 2),
            AwinFeed::row(['price' => 'not a price'], 3),
            AwinFeed::row(['ean' => "\x80 garbage \xff"], 4),
            AwinFeed::row([], 5),
            AwinFeed::row([], 5), // duplicate product_id inside the file
        ]);

        $file = $this->parser->parse($csv);

        self::assertSame(6, $file->totalRows);
        self::assertCount(1, $file->rows);
        self::assertSame('BC-1005', $file->rows[0]->productId);
        self::assertCount(5, $file->errors);
        self::assertSame(1, $file->errors[0]['row']);
        self::assertStringContainsStringIgnoringCase('price', $file->errors[0]['message']);
        self::assertSame(2, $file->errors[1]['row']);
        self::assertSame(3, $file->errors[2]['row']);
        self::assertSame(4, $file->errors[3]['row']);
        self::assertStringContainsStringIgnoringCase('encoding', $file->errors[3]['message']);
        self::assertSame(6, $file->errors[4]['row']);
        self::assertStringContainsStringIgnoringCase('duplicate', $file->errors[4]['message']);
    }

    public function testDuplicateProductIdIsAnErrorOnTheLaterRow(): void
    {
        $file = $this->parser->parse(AwinFeed::csv([AwinFeed::row([], 7), AwinFeed::row([], 7)]));

        self::assertCount(1, $file->rows);
        self::assertSame(2, $file->errors[0]['row']);
        self::assertStringContainsStringIgnoringCase('duplicate', $file->errors[0]['message']);
    }

    public function testTheRowCapTruncatesTheFileWithAnError(): void
    {
        $rows = [];
        foreach (range(1, 20001) as $seed) {
            $rows[] = AwinFeed::row(['product_id' => 'BC-CAP-'.$seed, 'ean' => ''], $seed % 100);
        }

        $file = $this->parser->parse(AwinFeed::csv($rows));

        self::assertCount(20000, $file->rows);
        self::assertCount(1, $file->errors);
        self::assertSame(0, $file->errors[0]['row']);
        self::assertStringContainsStringIgnoringCase('20000', $file->errors[0]['message']);
    }

    public function testEmptyLinesAreSkipped(): void
    {
        $csv = AwinFeed::csv([AwinFeed::row()], )."\n\n";

        $file = $this->parser->parse($csv);

        self::assertSame(1, $file->totalRows);
        self::assertSame([], $file->errors);
    }
}
