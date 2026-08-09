<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: ??= on magic properties consults __get (#29228).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class MagicCoalesceAssignUsesGetVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'magic_property_nullcoalesce_assign.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/magic_property_nullcoalesce_assign.phpt',
            'magic_property_nullcoalesce_assign.phpt'
        );
        yield 'property_hook_nullcoalesce_assign.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/property_hook_nullcoalesce_assign.phpt',
            'property_hook_nullcoalesce_assign.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
