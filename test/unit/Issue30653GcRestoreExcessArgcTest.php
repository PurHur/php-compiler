<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for gc_enabled / restore_*_handler (#30653).
 *
 * php-src: ext/standard/basic_functions.c
 */
final class Issue30653GcRestoreExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30653_gc_restore_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30653_gc_restore_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "gc_enabled_0:ArgumentCountError:gc_enabled() expects exactly 0 arguments, 1 given\n"
            ."gc_enabled_1:ArgumentCountError:gc_enabled() expects exactly 0 arguments, 2 given\n"
            ."gc_enabled_2:OK:true\n"
            ."restore_error_handler_0:ArgumentCountError:restore_error_handler() expects exactly 0 arguments, 1 given\n"
            ."restore_error_handler_1:ArgumentCountError:restore_error_handler() expects exactly 0 arguments, 2 given\n"
            ."restore_error_handler_2:OK:true\n"
            ."restore_exception_handler_0:ArgumentCountError:restore_exception_handler() expects exactly 0 arguments, 1 given\n"
            ."restore_exception_handler_1:ArgumentCountError:restore_exception_handler() expects exactly 0 arguments, 2 given\n"
            ."restore_exception_handler_2:OK:true\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
