<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** is_iterable()/is_countable() Reflection mixed $value → bool (#26106, basic_functions.stub.php). */
final class IsIterableCountableReflectionTest extends TestCase
{
    public function testReflectionMatchesZendStubs(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_26106_is_iterable_countable_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_26106.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "is_iterable param=mixed return=bool\n"
            ."is_countable param=mixed return=bool\n"
            ."is_iterable_named=true\n"
            ."is_countable_named=true\n",
            ob_get_clean()
        );
    }
}
