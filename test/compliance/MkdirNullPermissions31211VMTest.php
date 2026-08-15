<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: mkdir null $permissions under strict_types → TypeError (#31211).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class MkdirNullPermissions31211VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mkdir_null_permissions_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mkdir_null_permissions_strict.phpt',
            'mkdir_null_permissions_strict.phpt'
        );
        yield 'mkdir_null_permissions_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mkdir_null_permissions_soft_dep.phpt',
            'mkdir_null_permissions_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
