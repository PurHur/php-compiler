<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_extension_funcs() ArgumentCountError wording matches Zend (#30784).
 *
 * php-src: ext/standard/basic_functions.c
 */
final class Issue30784GetExtensionFuncsExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30784_get_extension_funcs_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30784_get_extension_funcs_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'hi:ArgumentCountError:get_extension_funcs() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'lo:ArgumentCountError:get_extension_funcs() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
