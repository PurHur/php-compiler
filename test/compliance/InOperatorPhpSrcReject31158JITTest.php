<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: `in` operator is a php-src Parse error; foreach / in_array unchanged (#31158).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class InOperatorPhpSrcReject31158JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'in_operator_php_src_reject_31158.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/in_operator_php_src_reject_31158.phpt',
            'in_operator_php_src_reject_31158.phpt'
        );
        yield 'in_operator_foreach_in_array_ok_31158.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/in_operator_foreach_in_array_ok_31158.phpt',
            'in_operator_foreach_in_array_ok_31158.phpt'
        );
        yield 'enum_in_operator.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_in_operator.phpt',
            'enum_in_operator.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
