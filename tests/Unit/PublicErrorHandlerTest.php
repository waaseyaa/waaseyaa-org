<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\PublicErrorHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The public error boundary must never leak what went wrong. These are
 * the regression tests behind the "generic 500 with a correlation id"
 * production guarantee: secrets, filesystem paths, SQL fragments and
 * exception class names stay on the server side of the boundary.
 */
final class PublicErrorHandlerTest extends TestCase
{
    private const LEAKY_MESSAGE = 'SQLSTATE[HY000] [14] unable to open database file '
        . '/app/storage/waaseyaa.sqlite dsn=sqlite: password=hunter2 '
        . 'SELECT secret_column FROM user WHERE api_key = "sk-ant-e5c3"';

    #[Test]
    public function response_contains_no_exception_details(): void
    {
        $response = PublicErrorHandler::respond(
            new \RuntimeException(self::LEAKY_MESSAGE),
            static function (string $line): void {},
        );

        $body = (string) $response->getContent();

        self::assertSame(500, $response->getStatusCode());
        foreach ([
            'SQLSTATE',
            '/app/storage',
            'waaseyaa.sqlite',
            'hunter2',
            'sk-ant-e5c3',
            'SELECT',
            'api_key',
            'RuntimeException',
            'unable to open database file',
        ] as $fragment) {
            self::assertStringNotContainsStringIgnoringCase($fragment, $body, "Leaked fragment: $fragment");
        }
    }

    #[Test]
    public function response_and_header_carry_the_same_correlation_id(): void
    {
        $response = PublicErrorHandler::respond(
            new \RuntimeException('boom'),
            static function (string $line): void {},
        );

        $header = (string) $response->headers->get('X-Request-Id');
        self::assertMatchesRegularExpression('/^req_[a-f0-9]{16}$/', $header);
        self::assertStringContainsString($header, (string) $response->getContent());
    }

    #[Test]
    public function server_side_log_carries_the_details_and_the_id(): void
    {
        $captured = '';
        $response = PublicErrorHandler::respond(
            new \RuntimeException(self::LEAKY_MESSAGE),
            static function (string $line) use (&$captured): void {
                $captured = $line;
            },
        );

        $record = json_decode($captured, true);
        self::assertIsArray($record);
        self::assertSame('public_error_boundary', $record['event']);
        self::assertSame(\RuntimeException::class, $record['exception']);
        self::assertStringContainsString('hunter2', $record['message']);
        self::assertSame($record['correlation_id'], $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function response_is_json_api_shaped_and_uncacheable(): void
    {
        $response = PublicErrorHandler::respond(new \LogicException('x'), static function (string $l): void {});

        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $doc = json_decode((string) $response->getContent(), true);
        self::assertSame('500', $doc['errors'][0]['status']);
        self::assertSame('Internal Server Error', $doc['errors'][0]['title']);
    }
}
