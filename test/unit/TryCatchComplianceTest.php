<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #2084 / #57: try/catch/throw VM compliance slice for ci-fast. */
final class TryCatchComplianceTest extends TestCase
{
    public function testCatchRunsAfterThrow(): void
    {
        $this->assertVmOutput(
            '<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "caught\n";
}
',
            "caught\n"
        );
    }

    public function testCatchThenFallthrough(): void
    {
        $this->assertVmOutput(
            '<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "catch\n";
}
echo "after\n";
',
            "catch\nafter\n"
        );
    }

    public function testThrowUnwindsToOuterCatch(): void
    {
        $this->assertVmOutput(
            '<?php
class Ex {}
class Other {}
try {
    try {
        throw new Ex();
    } catch (Other $e) {
        echo "inner\n";
    }
} catch (Ex $e) {
    echo "caught\n";
}
',
            "caught\n"
        );
    }

    public function testUncaughtThrowNonZeroExit(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $code = '<?php
class Ex {}
throw new Ex();
';
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
