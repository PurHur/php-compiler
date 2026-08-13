<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for array_change_key_case/array_count_values (#30536).
 *
 * php-src: ext/standard/array.c / basic_functions.stub.php
 */
final class Issue30536ArrayChangeKeyCaseCountValuesExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_change_key_case_count_values_argcount.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_array_change_key_case_count_values_argcount.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "array_change_key_case() expects at most 2 arguments, 3 given\n"
            ."array_change_key_case() expects at least 1 argument, 0 given\n"
            ."array_count_values() expects exactly 1 argument, 2 given\n"
            ."array_count_values() expects exactly 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
        $this->assertStringNotContainsString('requires one or two arguments', $out);
    }
}
