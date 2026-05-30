<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #3371: builtin Error subclass hierarchy (TypeError, ValueError, …). */
final class ErrorHierarchyTest extends TestCase
{
    public function testTypeErrorCaughtAsError(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new TypeError("bad type");
} catch (Error $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
',
            "TypeError\nbad type\n"
        );
    }

    public function testValueErrorNotCaughtAsTypeError(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new ValueError("bad value");
} catch (TypeError $e) {
    echo "wrong\n";
} catch (Error $e) {
    echo get_class($e), "\n";
}
',
            "ValueError\n"
        );
    }

    public function testArgumentCountErrorCaughtAsThrowable(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new ArgumentCountError("too few");
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
',
            "ArgumentCountError\n"
        );
    }

    public function testParseErrorInheritance(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new ParseError("syntax");
} catch (Error $e) {
    echo get_class($e), "\n";
}
',
            "ParseError\n"
        );
    }

    public function testModuloByZeroCaughtAsArithmeticError(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    1 % 0;
} catch (ArithmeticError $e) {
    echo get_class($e), "\n";
}
',
            "DivisionByZeroError\n"
        );
    }

    public function testDivisionByZeroErrorExtendsArithmeticError(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new DivisionByZeroError("div");
} catch (ArithmeticError $e) {
    echo get_class($e), "\n";
}
',
            "DivisionByZeroError\n"
        );
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // exit() in compiled code
        }
        $actual = ob_get_clean();
        $this->assertSame($expected, $actual, 'VM stdout');
    }
}
