<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;

/**
 * D09.3: every API error lands in the JSON-lines journal, a successful answer
 * writes nothing. The file is shared by the whole run, so the test reads the
 * last line instead of the whole file.
 */
final class ApiErrorLogTest extends ApiTestCase
{
    public function testAnErrorIsJournaledAndASuccessIsNot(): void
    {
        $log = (string) static::getContainer()->getParameter('app.api_error_log');
        @unlink($log);

        $this->client->request('GET', '/api/products/definitely-missing', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);

        $line = $this->lastLine($log);
        self::assertNotNull($line, 'the error was journaled');
        self::assertSame(['/api/products/definitely-missing', 404, 'GET'], [$line['path'], $line['status'], $line['method']]);
        self::assertSame('Product not found', $line['message']);

        // a clean answer stays out of the journal
        $this->client->request('GET', '/api/products', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $line = $this->lastLine($log);
        self::assertNotNull($line);
        self::assertNotSame('/api/products', $line['path'], 'no journal line for a 200');
    }

    /** @return array<string, mixed>|null */
    private function lastLine(string $log): ?array
    {
        if (!is_file($log)) {
            return null;
        }
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        $last = end($lines);

        return $last === false || $last === '' ? null : json_decode($last, true);
    }
}
