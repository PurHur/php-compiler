<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for php_uname/get_loaded_extensions/getrusage (#30537).
 *
 * php-src: ext/standard/basic_functions.c / basic_functions.stub.php
 */
final class Issue30537InfoUnameExtensionsGetrusageExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_info_uname_extensions_getrusage_argcount.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_info_uname_extensions_getrusage_argcount.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "php_uname() expects at most 1 argument, 2 given\n"
            ."get_loaded_extensions() expects at most 1 argument, 2 given\n"
            ."getrusage() expects at most 1 argument, 2 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
        $this->assertStringNotContainsString('accepts at most one argument', $out);
    }
}
