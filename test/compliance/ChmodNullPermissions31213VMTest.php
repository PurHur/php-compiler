<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: chmod null $permissions under strict_types → TypeError (#31213).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ChmodNullPermissions31213VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'chmod_null_permissions_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/chmod_null_permissions_strict.phpt',
            'chmod_null_permissions_strict.phpt'
        );
        yield 'chmod_null_permissions_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/chmod_null_permissions_soft_dep.phpt',
            'chmod_null_permissions_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
