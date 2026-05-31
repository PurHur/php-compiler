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

    public function testRethrowPreservesOriginalLine(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("x");
} catch (Exception $e) {
    try {
        throw $e;
    } catch (Exception $e2) {
        echo $e2->getLine() >= 1 ? "rethrow_ok\n" : "rethrow_bad\n";
    }
}
',
            "rethrow_ok\n"
        );
    }

    public function testDeferredThrowUsesCreationLine(): void
    {
        $this->assertVmOutput(
            '<?php
$e = new Exception("x");
try {
    throw $e;
} catch (Exception $ex) {
    echo $ex->getLine() >= 1 ? "deferred_ok\n" : "deferred_bad\n";
}
',
            "deferred_ok\n"
        );
    }

    public function testUncaughtExceptionNonZeroExit(): void
    {
        $this->assertVmCliExit('<?php throw new Exception("boom");', null);
    }

    /** Guards bin/vm.php stdin path (compliance PHPTs); Runtime-only tests miss merge resume (#195). */
    public function testThrowExceptionCaughtViaVmCli(): void
    {
        $this->assertVmCliOutput(
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

    private function assertVmCliOutput(string $code, string $expected): void
    {
        [$stdout, $exit] = $this->runVmCli($code);
        $this->assertSame(0, $exit, 'VM CLI exit');
        $this->assertSame($expected, $stdout, 'VM CLI stdout');
    }

    /** @return array{0: int|null, 1: int} exit code; null expected means any non-zero */
    private function assertVmCliExit(string $code, ?int $expectedExit): void
    {
        [, $exit] = $this->runVmCli($code);
        if (null === $expectedExit) {
            $this->assertNotSame(0, $exit);
        } else {
            $this->assertSame($expectedExit, $exit);
        }
    }

    /** @return array{0: string, 1: int} stdout and exit code */
    private function runVmCli(string $code): array
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $bin], $descriptor, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$stdout, $exit];
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
