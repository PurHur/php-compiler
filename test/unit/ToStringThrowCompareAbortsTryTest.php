<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29534 — __toString throw during == / < / <=> must abort the try body
 * (Zend zend_compare.c / zend_object_handlers.c parity). Catch runs once; no AFTER;
 * no userspace MagicMethodInvocationAborted.
 */
final class ToStringThrowCompareAbortsTryTest extends TestCase
{
    public function testToStringThrowDuringCompareAbortsTryBody(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/tostring_throw_compare_aborts_try_29534.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'tostring_throw_compare_aborts_try_29534.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "caught_direct:t\n"
            ."caught_eq:Exception:t\n"
            ."caught_lt:Exception:t\n"
            ."caught_sp:Exception:t\n"
            ."caught_arrow:Exception:t\n"
            ."DONE\n",
            $output
        );
    }
}
