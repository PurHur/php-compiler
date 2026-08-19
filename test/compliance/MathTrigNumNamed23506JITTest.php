<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: sin-family Reflection $num + Zend named params (#23506).
 */
final class MathTrigNumNamed23506JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'math_trig_num_named_23506.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/math_trig_num_named_23506.phpt',
            'math_trig_num_named_23506.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
