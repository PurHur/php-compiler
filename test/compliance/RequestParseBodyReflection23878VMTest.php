<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: request_parse_body Reflection + Zend named options (#23878).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class RequestParseBodyReflection23878VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'request_parse_body_reflection_23878.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/request_parse_body_reflection_23878.phpt',
            'request_parse_body_reflection_23878.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        unset($_ENV['PHP_COMPILER_PROFILE']);
        putenv('PHP_COMPILER_PROFILE');
    }
}
