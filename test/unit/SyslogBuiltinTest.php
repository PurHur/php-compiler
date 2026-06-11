<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\closelog;
use PHPCompiler\ext\standard\openlog;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\syslog;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmSyslog;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for openlog()/syslog()/closelog() (#3676). */
final class SyslogBuiltinTest extends TestCase
{
    public function test_functions_registered(): void
    {
        $runtime = new Runtime();
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'openlog'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'syslog'));
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'closelog'));
    }

    public function test_log_info_constant_value(): void
    {
        self::assertSame(6, StdlibConstants::LOG_INFO);
        self::assertSame(8, StdlibConstants::LOG_USER);
    }

    public function test_vm_syslog_no_host_delegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSyslog.php');
        self::assertDoesNotMatchRegularExpression('/\\\\openlog\\s*\\(/', $source);
        self::assertDoesNotMatchRegularExpression('/\\\\syslog\\s*\\(/', $source);
        self::assertDoesNotMatchRegularExpression('/\\\\closelog\\s*\\(/', $source);
    }

    public function test_syslog_round_trip_when_libc_available(): void
    {
        if (!VmSyslog::available()) {
            self::markTestSkipped('libc syslog unavailable (FFI disabled or missing)');
        }

        $runtime = new Runtime();
        $open = new openlog();
        $openFrame = $open->getFrame($runtime->vmContext);
        $openFrame->returnVar = new VMVariable();
        $ident = new VMVariable();
        $ident->string('phpc-test');
        $openFrame->calledArgs[] = $ident;
        $option = new VMVariable();
        $option->int(StdlibConstants::LOG_PID | StdlibConstants::LOG_CONS);
        $openFrame->calledArgs[] = $option;
        $facility = new VMVariable();
        $facility->int(StdlibConstants::LOG_USER);
        $openFrame->calledArgs[] = $facility;
        $open->execute($openFrame);
        self::assertTrue($openFrame->returnVar->resolveIndirect()->toBool());

        $log = new syslog();
        $logFrame = $log->getFrame($runtime->vmContext);
        $logFrame->returnVar = new VMVariable();
        $priority = new VMVariable();
        $priority->int(StdlibConstants::LOG_INFO);
        $logFrame->calledArgs[] = $priority;
        $message = new VMVariable();
        $message->string('unit test');
        $logFrame->calledArgs[] = $message;
        $log->execute($logFrame);
        self::assertTrue($logFrame->returnVar->resolveIndirect()->toBool());

        $close = new closelog();
        $closeFrame = $close->getFrame($runtime->vmContext);
        $closeFrame->returnVar = new VMVariable();
        $close->execute($closeFrame);
        self::assertTrue($closeFrame->returnVar->resolveIndirect()->toBool());
    }

    private const JIT_CODE = <<<'PHP'
openlog('phpc-test', LOG_PID | LOG_CONS, LOG_USER);
$ok = syslog(LOG_INFO, 'parity ok');
closelog();
echo $ok ? "true\n" : "false\n";
echo "called\n";
PHP;

    /**
     * @group llvm
     * @group jit
     */
    public function testJitNativeMatchesVmSubset(): void
    {
        if (!VmSyslog::available()) {
            self::markTestSkipped('libc syslog unavailable (FFI disabled or missing)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            self::markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame("true\ncalled\n", $this->runJitBinary());
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeMatchesVmSubset(): void
    {
        if (!VmSyslog::available()) {
            self::markTestSkipped('libc syslog unavailable (FFI disabled or missing)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            self::markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame("true\ncalled\n", $this->runAotBinary());
    }

    private function runJitBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_syslog_jit_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::JIT_CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $run = proc_open(
            ['php', $repo.'/bin/jit.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($run);
        @unlink($tmp);
        $this->assertSame(0, $code, $stderr ?: 'jit run failed');

        return $stdout;
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_syslog_aot_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::JIT_CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileCode = proc_close($compile);
        $this->assertSame(0, $compileCode, $compileErr ?: 'compile failed');
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $runErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $runCode = proc_close($run);
        @unlink($tmp);
        @unlink($out);
        $this->assertSame(0, $runCode, $runErr ?: 'run failed');

        return $stdout;
    }
}
