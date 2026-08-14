<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * exec/system/passthru/shell_exec excess argc → Zend ArgumentCountError (#30566).
 *
 * php-src: ext/standard/exec.c PHP_FUNCTION(exec|system|passthru|shell_exec)
 */
final class Issue30566ExecFamilyExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30566_exec_family_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30566_exec_family_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "shell_hi:ArgumentCountError:shell_exec() expects exactly 1 argument, 2 given\n"
            ."shell_lo:ArgumentCountError:shell_exec() expects exactly 1 argument, 0 given\n"
            ."system:ArgumentCountError:system() expects at most 2 arguments, 3 given\n"
            ."passthru:ArgumentCountError:passthru() expects at most 2 arguments, 3 given\n"
            ."exec:ArgumentCountError:exec() expects at most 3 arguments, 4 given\n"
            ."exec_lo:ArgumentCountError:exec() expects at least 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('accepts one', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
