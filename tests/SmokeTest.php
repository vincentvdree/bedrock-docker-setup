<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SmokeTest extends TestCase
{
    public function testHomepage(): void
    {
        // Test if the website can boot and the homepage is accessible
        $url = self::homeUrl();

        $body = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                // Read the body even on a 4xx/5xx, so failures show the response.
                'ignore_errors' => true,
            ],
        ]));

        self::assertIsString($body, sprintf('Could not reach %s — is the stack up? (make up)', $url));
        self::assertSame(200, self::statusCode(http_get_last_response_headers() ?? []), sprintf('Unexpected status for %s', $url));
        self::assertStringContainsString('<html', $body, 'Response is not an HTML document');
        self::assertStringNotContainsString('Error establishing a database connection', $body);
    }

    /**
     * WP_HOME comes from the environment in the container; fall back to .env
     * so the test also works when PHPUnit runs outside Docker.
     */
    private static function homeUrl(): string
    {
        $home = getenv('WP_HOME')
            ?: Dotenv::createArrayBacked(dirname(__DIR__))->load()['WP_HOME'] ?? null;

        self::assertIsString($home, 'WP_HOME is not set');

        return rtrim(trim($home, '\'"'), '/').'/';
    }

    /**
     * @param list<string> $headers
     */
    private static function statusCode(array $headers): int
    {
        // Redirects leave one status line per hop; the last one is the final response.
        $statuses = array_values(array_filter($headers, static fn (string $h): bool => str_starts_with($h, 'HTTP/')));

        self::assertNotEmpty($statuses, 'No HTTP status line in the response');

        preg_match('/\s(\d{3})\s/', end($statuses).' ', $matches);

        return (int) ($matches[1] ?? 0);
    }
}
