<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: AssignRef of nullsafe chain is compile fatal (#26638).
 */
final class NullsafeByRefRhsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'nullsafe_byref_rhs.phpt',
            'nullsafe_byref_rhs_method.phpt',
            'nullsafe_byref_rhs_chained.phpt',
            'nullsafe_write_context.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
