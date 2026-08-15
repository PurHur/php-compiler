<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: chmod null $permissions under strict_types → TypeError (#31213).
 */
final class ChmodNullPermissions31213JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'chmod_null_permissions_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/chmod_null_permissions_strict_jit.phpt',
            'chmod_null_permissions_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
