<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: eval(typed class const) is catchable ParseError on PROFILE=8.2 (#31860).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class EvalTypedClassConstParse31860JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'eval_typed_class_const_reject_catchable.phpt',
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
