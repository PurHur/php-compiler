<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for case/HTML/type builtins (#28317).
 *
 * php-src: ext/standard/string.stub.php, html.stub.php, type.c, basic_functions.c
 */
final class Issue28317ExcessArgcArgumentCountTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28317.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28317.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'strtolower:ArgumentCountError:strtolower() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ucwords:ArgumentCountError:ucwords() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'htmlentities:ArgumentCountError:htmlentities() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'html_entity_decode:ArgumentCountError:html_entity_decode() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'get_debug_type:ArgumentCountError:get_debug_type() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'is_iterable:ArgumentCountError:is_iterable() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
