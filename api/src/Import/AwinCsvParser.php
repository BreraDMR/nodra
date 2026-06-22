<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Reads the Awin feed CSV: plain UTF-8 with or without a BOM, or gzip. Quoted fields with commas
 * and doubled quotes follow the CSV rules. The header decides the columns; the documented required
 * ones must all be there or the whole file is refused. A broken row is an error row and the run goes on.
 */
final class AwinCsvParser
{
    /** The hard row cap per run (spec default); beyond it the file is truncated with a file-level error. */
    public const MAX_ROWS = 20000;

    private const REQUIRED_COLUMNS = ['product_id', 'product_name', 'price', 'currency', 'deep_link'];

    public function parse(string $bytes): AwinParsedFile
    {
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $unpacked = @gzdecode($bytes);
            if ($unpacked === false) {
                throw new \InvalidArgumentException('The file is not a readable CSV or gzip archive');
            }
            $bytes = $unpacked;
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        $lines = $this->records($bytes);
        $header = array_shift($lines);
        if ($header === null) {
            throw new \InvalidArgumentException('The file is empty; a CSV with a header row is expected');
        }
        $header = array_map(static fn (string $name): string => strtolower(trim($name, " \t\"")), $header);
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf('The file is missing the required column(s): %s', implode(', ', $missing)));
        }
        $indexes = [];
        foreach ($header as $index => $name) {
            $indexes[$name] ??= $index;
        }

        $rows = [];
        $errors = [];
        $seenIds = [];
        $number = 0;
        foreach ($lines as $fields) {
            if (trim(implode('', $fields)) === '') {
                continue;
            }
            $number++;
            if ($number > self::MAX_ROWS) {
                $errors[] = ['row' => 0, 'message' => sprintf('The file has more than %d data rows; everything past that was not read.', self::MAX_ROWS)];
                break;
            }
            foreach ($fields as $field) {
                if (!mb_check_encoding($field, 'UTF-8')) {
                    $errors[] = ['row' => $number, 'message' => 'Encoding garbage in this row; it is skipped'];
                    continue 2;
                }
            }
            try {
                $row = $this->row($number, $fields, $indexes);
            } catch (AwinRowException $error) {
                $errors[] = ['row' => $number, 'message' => $error->getMessage()];
                continue;
            }
            if (isset($seenIds[$row->productId])) {
                $errors[] = ['row' => $number, 'message' => sprintf('Duplicate product_id "%s" inside the file', $row->productId)];
                continue;
            }
            $seenIds[$row->productId] = true;
            $rows[] = $row;
        }

        return new AwinParsedFile($rows, $errors, $number);
    }

    /** @return list<list<string>> */
    private function records(string $text): array
    {
        $records = [];
        $len = strlen($text);
        $i = 0;
        $field = '';
        $fields = [];
        $quoted = false;
        $started = false;
        while ($i < $len) {
            $char = $text[$i];
            if ($quoted) {
                if ($char === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i += 2;
                        continue;
                    }
                    $quoted = false;
                    $i++;
                    continue;
                }
                $field .= $char;
                $i++;
                continue;
            }
            if ($char === '"') {
                $quoted = true;
                $started = true;
                $i++;
                continue;
            }
            if ($char === ',') {
                $fields[] = $field;
                $field = '';
                $i++;
                $started = true;
                continue;
            }
            if ($char === "\n" || $char === "\r") {
                $fields[] = $field;
                $records[] = $fields;
                $field = '';
                $fields = [];
                $started = false;
                $i++;
                if ($char === "\r" && ($text[$i] ?? '') === "\n") {
                    $i++;
                }
                continue;
            }
            $next = strcspn($text, ",\"\r\n", $i);
            if ($next > 0) {
                $field .= substr($text, $i, $next);
                $started = true;
                $i += $next;
            }
        }
        if ($started || $field !== '' || $fields !== []) {
            $fields[] = $field;
            $records[] = $fields;
        }

        return $records;
    }

    /**
     * @param list<string> $fields
     * @param array<string, int> $indexes
     */
    private function row(int $rowNumber, array $fields, array $indexes): AwinRow
    {
        $value = static fn (string $column): ?string => isset($indexes[$column]) && ($raw = trim($fields[$indexes[$column]] ?? '')) !== '' ? $raw : null;
        $productId = $value('product_id') ?? throw new AwinRowException($rowNumber, 'Missing product_id');
        $name = $value('product_name') ?? throw new AwinRowException($rowNumber, 'Missing product name');
        $price = $value('price') ?? throw new AwinRowException($rowNumber, 'Missing price');
        $currency = $value('currency') ?? throw new AwinRowException($rowNumber, 'Missing currency');
        if (!preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
            throw new AwinRowException($rowNumber, sprintf('Unparseable currency "%s"', $currency));
        }
        $deepLink = $value('deep_link') ?? throw new AwinRowException($rowNumber, 'Missing deep_link');
        if (strlen($deepLink) > 2048) {
            throw new AwinRowException($rowNumber, 'The deep_link is longer than 2048 characters');
        }
        $priceMinor = self::minor($price) ?? throw new AwinRowException($rowNumber, sprintf('Unparseable price "%s"', $price));
        $rrp = $value('rrp_price');
        $rrpMinor = null;
        if ($rrp !== null) {
            $rrpMinor = self::minor($rrp) ?? throw new AwinRowException($rowNumber, sprintf('Unparseable rrp_price "%s"', $rrp));
        }
        $images = array_values(array_unique(array_filter([
            $value('image_url'),
            $value('large_image'),
            $value('alternate_image'),
        ])));

        return new AwinRow(
            $rowNumber,
            $productId,
            $name,
            $value('description'),
            $value('brand_name'),
            $value('ean'),
            $value('mpn') ?? $value('model_number'),
            $value('colour'),
            $value('size'),
            $priceMinor,
            strtoupper($currency),
            $rrpMinor,
            self::bool($value('in_stock')),
            $value('stock_status'),
            self::whole($value('number_available')) ?? self::whole($value('stock_quantity')),
            $value('delivery_time'),
            $images,
            $value('merchant_category'),
            $value('merchant_product_category_path'),
            $deepLink,
            $value('last_updated'),
        );
    }

    /** "89.90" and "89,90" both read as 8990 minor units; anything else is not a price. */
    private static function minor(string $value): ?int
    {
        $value = str_replace(["\u{a0}", ' '], '', trim($value));
        if (!preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $value, $parts)) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    private static function whole(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d+$/', $value) ? (int) $value : null;
    }

    private static function bool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);

        return in_array($value, ['yes', 'true', 'y', '1'], true) ? true : (in_array($value, ['no', 'false', 'n', '0'], true) ? false : null);
    }
}
