<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: eval('') returns false (zend_eval_stringl FAILURE), not NULL (#31914).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class EvalEmptyReturnsFalse31914JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'eval_empty_returns_false.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
