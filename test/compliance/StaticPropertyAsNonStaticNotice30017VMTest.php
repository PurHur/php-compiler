<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: static property via -> emits Notice then undefined/dynamic (#30017).
 */
require_once __DIR__.'/../BaseTest.php';

final class StaticPropertyAsNonStaticNotice30017VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_property_as_non_static_notice.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_property_as_non_static_notice.phpt',
            'static_property_as_non_static_notice.phpt'
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
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
