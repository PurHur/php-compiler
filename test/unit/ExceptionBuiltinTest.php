<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #195: builtin Exception / Error / Throwable VM classes. */
final class ExceptionBuiltinTest extends TestCase
{
    public function testThrowExceptionCaughtWithGetMessage(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("x");
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
}
',
            "x\n0\n"
        );
    }

    public function testCatchThrowableMatchesExceptionAndError(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("ex");
} catch (Throwable $e) {
    echo "E:", $e->getMessage(), "\n";
}

try {
    throw new Error("er");
} catch (Throwable $e) {
    echo "R:", $e->getMessage(), "\n";
}
',
            "E:ex\nR:er\n"
        );
    }

    public function testExceptionGetFileIsSet(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("boom");
} catch (Exception $e) {
    echo $e->getFile() !== "" ? "file_ok\n" : "file_bad\n";
}
',
            "file_ok\n"
        );
    }

    public function testExceptionGetLineIsThrowSite(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("boom");
} catch (Exception $e) {
    echo $e->getLine() >= 1 ? "line_ok\n" : "line_bad\n";
}
',
            "line_ok\n"
        );
    }

    public function testUncaughtExceptionNonZeroExit(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $code = '<?php throw new Exception("boom");';
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $cmd = [$php, $bin];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertNotSame(0, $exit);
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
