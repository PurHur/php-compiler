<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** max/min/pow/fdiv Reflection vs Zend stubs (#25459). */
final class MaxMinPowFdivReflectionTest extends TestCase
{
    public function testReflectionAndNamedArgs(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25459_max_min_pow_fdiv_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25459.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "max ret=mixed params=\$value:mixed,\$values:mixed...,\n"
            ."min ret=mixed params=\$value:mixed,\$values:mixed...,\n"
            ."pow ret=object|int|float params=\$num:mixed,\$exponent:mixed,\n"
            ."fdiv ret=float params=\$num1:float,\$num2:float,\n"
            ."fmod ret=float params=\$num1:float,\$num2:float,\n"
            ."pow_named=8\n"
            ."fmod_named=1.5\n"
            ."legacy_pow ERR=Unknown named parameter \$base\n",
            ob_get_clean()
        );
    }
}
