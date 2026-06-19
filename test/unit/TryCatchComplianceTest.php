<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #2084 / #57: try/catch/throw VM compliance slice for ci-fast. */
final class TryCatchComplianceTest extends TestCase
{
    /** Issue #10016 / #3508: bare `throw;` rethrows active catch exception to outer handler. */
    public function testBareThrowRethrowNestedCatchPrintsMessage(): void
    {
        $this->assertVmOutput(
            <<<'PHP'
<?php
try {
    try {
        throw new Exception('inner');
    } catch (Exception $e) {
        throw;
    }
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
PHP
            ,
            "inner\n"
        );
    }

    /** Issue #10016: same-line throw expr + bare rethrow must not treat `throw new` as rethrow. */
    public function testBareThrowRethrowSameLineAsThrowExpression(): void
    {
        $this->assertVmOutput(
            <<<'PHP'
<?php
try { try { throw new Exception('inner'); } catch (Exception $e) { throw; } } catch (Exception $e) { echo $e->getMessage(), "\n"; }
PHP
            ,
            "inner\n"
        );
    }

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

    public function testCatchRunsBeforeFinallyOnMatchedThrow(): void
    {
        $this->assertVmOutput(
            '<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "catch\n";
} finally {
    echo "finally\n";
}
echo "after\n";
',
            "catch\nfinally\nafter\n"
        );
    }

    public function testFinallyRunsOnNormalTryCompletion(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    echo "try\n";
} finally {
    echo "finally\n";
}
echo "after\n";
',
            "try\nfinally\nafter\n"
        );
    }

    public function testFinallyRunsBeforeReturnFromTry(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    echo "try\n";
    return;
} finally {
    echo "finally\n";
}
echo "after\n";
',
            "try\nfinally\n"
        );
    }

    public function testFinallyRunsBeforeReturnValueFromTry(): void
    {
        $this->assertVmOutput(
            '<?php
function f() {
    try {
        return 42;
    } finally {
        echo "finally\n";
    }
}
echo f(), "\n";
',
            "finally\n42\n"
        );
    }

    /** Issue #5867: uncaught fatal must mention Next Exception for finally over try (zend_exceptions.c). */
    public function testFinallyThrowUncaughtFatalShowsNextException(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $code = '<?php
try {
    throw new Exception("inner");
} finally {
    throw new Exception("finally");
}
';
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
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertNotSame(0, $exit);
        $this->assertIsString($stderr);
        $this->assertStringContainsString('Uncaught Exception: inner', $stderr);
        $this->assertStringContainsString('Next Exception: finally', $stderr);
    }

    /** Issue #6457: reused throw variable must still chain inner on finally uncaught fatal. */
    public function testFinallyThrowOperandAliasChainsOnUncaughtFatal(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $code = '<?php
$e = new Exception("inner");
try {
    throw $e;
} finally {
    $e = new Exception("finally");
    throw $e;
}
';
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
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertNotSame(0, $exit);
        $this->assertIsString($stderr);
        $this->assertStringContainsString('Uncaught Exception: inner', $stderr);
        $this->assertStringContainsString('Next Exception: finally', $stderr);
        $this->assertStringNotContainsString('memory size', $stderr);
    }

    /** Issue #5486: finally throw must chain pending try exception (zend_exceptions.c). */
    public function testFinallyThrowChainsPendingException(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    try {
        throw new Exception("inner");
    } finally {
        throw new Exception("finally");
    }
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    $p = $e->getPrevious();
    echo $p ? $p->getMessage() : "null", "\n";
}
',
            "finally\ninner\n"
        );
    }

    /** Issue #5331: finally throw must discard pending return, not relaunch finally. */
    public function testFinallyThrowOverridesPendingReturn(): void
    {
        $this->assertVmOutput(
            '<?php
function g(): int {
    try {
        return 1;
    } finally {
        throw new Exception("f");
    }
}
try {
    var_dump(g());
} catch (Throwable $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
',
            "caught: f\n"
        );
    }

    /** Issue #8574: caught exception in function must resume caller (Zend/zend_execute.c). */
    public function testFunctionTryCatchResumesCallerAfterCaughtException(): void
    {
        $this->assertVmOutput(
            '<?php
function probe(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ok\n";
    } catch (TypeError $e) {
        echo $label, ": caught\n";
    }
}
probe("p1", static function (): void {
    substr_compare([], "a", 0);
});
probe("p2", static function (): void {
    substr_compare("abc", [], 0);
});
echo "after\n";
',
            "p1: caught\np2: caught\nafter\n"
        );
    }

    public function testNestedFinallyOnReturn(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    try {
        echo "inner\n";
        return;
    } finally {
        echo "inner-finally\n";
    }
} finally {
    echo "outer-finally\n";
}
echo "after\n";
',
            "inner\ninner-finally\nouter-finally\n"
        );
    }

    public function testFinallyRunsBeforeUncaughtThrow(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php
class Ex {}
try {
    throw new Ex();
} finally {
    echo "finally\n";
}
',
            'test.php'
        );
        ob_start();
        try {
            $runtime->run($block);
            $this->fail('expected uncaught exception');
        } catch (\Exception) {
            // finally must run before the VM maps the throw to a native exception
        }
        $this->assertSame("finally\n", ob_get_clean(), 'VM stdout');
    }

    /** Issue #4120: throw expression as assignment RHS (Zend zend_compile.c). */
    public function testThrowExpressionInAssignment(): void
    {
        $this->assertVmOutput(
            '<?php
function f(): int {
    $x = throw new LogicException("abort");
    return $x;
}
try {
    f();
} catch (LogicException $e) {
    echo $e->getMessage(), "\n";
}
',
            "abort\n"
        );
    }

    /** Issue #9209: throw expressions in return ?? and elvis ?: (Zend zend_compile.c). */
    public function testThrowExpressionReturnAndElvis(): void
    {
        $this->assertVmOutput(
            '<?php
function f(?int $x): int {
    return $x ?? throw new RuntimeException("x");
}
try {
    f(null);
} catch (RuntimeException $e) {
    echo "caught:", $e->getMessage(), "\n";
}
try {
    $y = 0 ?: throw new RuntimeException("y");
} catch (RuntimeException $e) {
    echo "caught:", $e->getMessage(), "\n";
}
',
            "caught:x\ncaught:y\n"
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
