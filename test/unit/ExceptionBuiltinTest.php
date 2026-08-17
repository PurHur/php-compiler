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

    /** void __construct must not null the TYPE_NEW temp (#4540, match throw arms). */
    public function testThrowBareNewExceptionCaught(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception();
} catch (Exception $e) {
    echo "caught\n";
}
',
            "caught\n"
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

    /** wrapEvalCode prepends `<?php\\n` — Zend getLine() is 1-based in the eval string (#31948). */
    public function testEvalThrowGetLineMatchesEvalString(): void
    {
        $this->assertVmOutput(
            '<?php
echo eval(\'return __LINE__;\');
echo "\n";
try {
    eval(\'throw new Exception("x");\');
} catch (Exception $e) {
    echo $e->getLine();
    echo "\n";
}
try {
    eval("\nthrow new Exception(\\"x\\");");
} catch (Exception $e) {
    echo $e->getLine();
    echo "\n";
}
',
            "1\n1\n2\n"
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

    public function testCatchVariableAfterNestedTryInCatchBody(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("x");
} catch (Exception $e) {
    try {
        echo "inner\n";
    } catch (Exception $ignored) {
    }
    echo $e->getMessage(), "\n";
}
',
            "inner\nx\n"
        );
    }

    public function testCatchVariableWithFinallyBlock(): void
    {
        $this->assertVmOutput(
            '<?php
try {
    throw new Exception("x");
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
} finally {
    echo "f\n";
}
',
            "x\nf\n"
        );
    }

    /** Issue #25870: Exception/Error private __clone — Reflection + uncloneable (zend_exceptions.stub.php). */
    public function testExceptionAndErrorPrivateCloneReflection(): void
    {
        $this->assertVmOutput(
            '<?php
$r = new ReflectionClass(Exception::class);
echo "has=" . ($r->hasMethod("__clone") ? "1" : "0") . "\n";
if ($r->hasMethod("__clone")) {
    $m = $r->getMethod("__clone");
    echo "private=" . ($m->isPrivate() ? "1" : "0") . "\n";
    echo "return=" . (string) $m->getReturnType() . "\n";
} else {
    echo "missing\n";
}
$r2 = new ReflectionClass(Error::class);
echo "error_has=" . ($r2->hasMethod("__clone") ? "1" : "0") . "\n";
if ($r2->hasMethod("__clone")) {
    echo "error_private=" . ($r2->getMethod("__clone")->isPrivate() ? "1" : "0") . "\n";
}
echo "cloneable=" . ((new ReflectionClass(Exception::class))->isCloneable() ? "1" : "0") . "\n";
try {
    clone new Exception("x");
    echo "cloned\n";
} catch (Throwable $t) {
    echo get_class($t) . "\n";
}
',
            "has=1\nprivate=1\nreturn=void\nerror_has=1\nerror_private=1\ncloneable=0\nError\n"
        );
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

    /** Issue #6334: uncaught builtin TypeError must fatal at user call site. */
    public function testUncaughtBuiltinTypeErrorFatalAtUserSite(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_uncaught_te_');
        $this->assertNotFalse($tmp);
        $script = $tmp . '.php';
        rename($tmp, $script);
        file_put_contents($script, '<?php
class C {}
$o = new C();
array_key_exists(\'k\', $o);
');
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $bin, $script], $descriptor, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($script);
        $this->assertNotSame(0, $exit);
        $this->assertIsString($stderr);
        $this->assertStringContainsString('Uncaught TypeError:', $stderr);
        $this->assertStringContainsString(basename($script) . ' on line 4', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
    }

    public function testErrorExceptionConstructAndGetSeverity(): void
    {
        $this->assertVmOutput(
            '<?php
$e = new ErrorException("m", 0, E_USER_WARNING, __FILE__, 42);
echo $e->getSeverity(), "\n";
$e2 = new ErrorException("probe", 0, E_USER_WARNING, __FILE__, 99);
echo $e2->getMessage(), ":", $e2->getSeverity(), "\n";
',
            "512\nprobe:512\n"
        );
    }

    /** Issue #23706: ErrorException Reflection params + named args match Zend stub. */
    public function testErrorExceptionReflectionParamsAndNamedConstruct(): void
    {
        $this->assertVmOutput(
            '<?php
$r = new ReflectionMethod(ErrorException::class, "__construct");
$names = [];
foreach ($r->getParameters() as $p) { $names[] = $p->getName(); }
echo "params=", implode(",", $names), "\n";
try {
    throw new ErrorException(message: "m", code: 1, severity: E_WARNING, filename: "f.php", line: 9);
} catch (ErrorException $e) {
    echo "named_ok severity=", $e->getSeverity(), " file=", $e->getFile(), " line=", $e->getLine(), "\n";
} catch (Throwable $e) {
    echo "named_fail ", get_class($e), ":", $e->getMessage(), "\n";
}
',
            "params=message,code,severity,filename,line,previous\nnamed_ok severity=2 file=f.php line=9\n"
        );
    }

    /**
     * User subclasses of Exception/Error must not fatal on Throwable LSP (#25868).
     * php-src Zend/zend_exceptions.stub.php + zend_inheritance.c.
     */
    public function testUserSubclassOfExceptionAndErrorMatchesZend(): void
    {
        $this->assertVmCliOutput(
            '<?php
echo "named_Exception=";
try {
    eval(\'class E_Ex extends Exception { public function x(): int { return 1; } }\');
    echo (new E_Ex("m"))->x() . "|" . (new E_Ex("m"))->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
echo "named_Error=";
try {
    eval(\'class E_Err extends Error { public function x(): int { return 1; } }\');
    echo (new E_Err("m"))->x() . "|" . (new E_Err("m"))->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
echo "named_RuntimeException=";
try {
    eval(\'class E_Re extends RuntimeException { public function x(): int { return 1; } }\');
    echo (new E_Re("m"))->x() . "|" . (new E_Re("m"))->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
echo "anon_Exception=";
try {
    $o = new class("m") extends Exception {
        public function x(): int { return 1; }
    };
    echo $o->x() . "|" . $o->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
',
            "named_Exception=1|m\nnamed_Error=1|m\nnamed_RuntimeException=1|m\nanon_Exception=1|m\n"
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
