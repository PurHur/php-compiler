<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: mkdir null $permissions under strict_types → TypeError (#31211).
 */
final class MkdirNullPermissions31211JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mkdir_null_permissions_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mkdir_null_permissions_strict_jit.phpt',
            'mkdir_null_permissions_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
