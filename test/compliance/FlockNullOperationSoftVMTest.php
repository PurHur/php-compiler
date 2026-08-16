<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: flock(null) soft Deprecated + ValueError (#31462).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class FlockNullOperationSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'flock_null_operation.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/flock_null_operation.phpt',
            'flock_null_operation.phpt'
        );
        yield 'flock_null_operation_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/flock_null_operation_strict.phpt',
            'flock_null_operation_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
