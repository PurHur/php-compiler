<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29521 — __toString throw during echo/print/(string) must abort the try body
 * (Zend zend_object_handlers.c / zend_execute.c parity). Catch runs once; no AFTER.
 */
final class ToStringThrowEchoAbortsTryTest extends TestCase
{
    public function testToStringThrowDuringEchoPrintCastAbortsTryBody(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/tostring_throw_echo_aborts_try_29521.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'tostring_throw_echo_aborts_try_29521.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "caught_echo:no\ncaught_print:no\ncaught_cast:no\ncaught_plain:plain\nDONE\n",
            $output
        );
    }

    public function testToStringThrowDuringStrlenAbortsTryBody(): void
    {
        $code = <<<'PHP'
<?php
$t = new class {
    public function __toString(): string {
        throw new Exception('boom');
    }
};
try {
    echo strlen($t);
    echo "AFTER\n";
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
echo "TAIL\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'tostring_throw_strlen_after.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("caught: boom\nTAIL\n", $output);
    }
}
