<?php

declare(strict_types=1);

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * LLVM AOT compile gates for bootstrap assignOperand / Runtime class-const blockers (#1074).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class BootstrapAotJitCompileTest extends TestCase
{
    public function testRuntimeModeAotClassConstFetchCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function bootstrap_runtime_mode(): int {
    return \PHPCompiler\Runtime::MODE_AOT;
}
echo bootstrap_runtime_mode(), "\n";
PHP;
        $this->assertCompileExitZero($source, 'Runtime::MODE_AOT class const fetch');
    }

    /** Issue #14472: standalone AOT init must not segfault on JsonEncode nested-JIT during loadJitContext. */
    public function testEchoHelloBootstrapAotCompileDoesNotSegfault(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/test/bootstrap-aot/echo_hello.php';
        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_echo_hello_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        if (is_file($outfile)) {
            @unlink($outfile);
        }
        $this->assertSame(0, $exitCode, 'echo_hello bootstrap AOT compile: '.$stderr);
        $this->assertStringNotContainsString('Segmentation fault', $stderr);
    }

    /** Nested array class constants (ProcessOpenJitHelper::DEFAULT_PIPE_DESCRIPTOR) must JIT (#1492 bootstrap). */
    public function testProcessOpenJitHelperNestedArrayClassConstCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/ext/standard/ProcessOpenJitHelper.php';
        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_process_open_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        if (is_file($outfile)) {
            @unlink($outfile);
        }
        $this->assertSame(0, $exitCode, 'ProcessOpenJitHelper AOT compile: '.$stderr);
        $this->assertStringNotContainsString('not jittable', $stderr);
    }

    public function testLoadJitContextStandaloneInitCompletes(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        $script = <<<'PHP'
<?php
require 'vendor/autoload.php';
$r = new PHPCompiler\Runtime(PHPCompiler\Runtime::MODE_AOT);
$r->loadJitContext();
echo "ok\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'load_jit_ctx_');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.php';
        rename($tmp, $path);
        file_put_contents($path, $script);
        $argv = array_merge(LlvmToolchain::envPrefix($repoRoot), [PHP_BINARY, $path]);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($argv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($path);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));
        $this->assertSame("ok\n", $stdout !== false ? $stdout : '');
    }

    public function testNativeStringArrayIntoMixedValueBoxCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function bootstrap_is_array_check(): string {
    $items = ['a', 'b', 'c'];
    return is_array($items) ? '1' : '0';
}
echo bootstrap_is_array_check(), "\n";
PHP;
        $stderr = $this->compileSource($source, 'native string array is_array');
        $this->assertStringNotContainsString('Source type: 196', $stderr);
    }

    /** Issue #11392: value×value TYPE_MUL/TYPE_MINUS must not abort JIT lowering (bin/compile.php spine). */
    public function testValueBoxMulMinusDoesNotAbortJitOnTypeMul(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
function pick(int $n): int|string
{
    return $n;
}
$a = pick(6);
$b = pick(7);
$c = pick(20);
echo ($a * $b), "\n";
echo ($c - $a), "\n";
PHP;
        $output = $this->compileSourceAllowFailure($source, 'value box mul minus');
        $this->assertStringNotContainsString(
            'TYPE_MUL for type pair 134 and 134',
            $output
        );
        $this->assertStringNotContainsString(
            "can't handle binary operation yet: TYPE_MUL for type pair",
            $output
        );
    }

    /** Self-host lint of production driver must not fail on FFI::cdef (#2633; full AOT link still LLVM 9 flaky). */
    public function testBinCompilePhpSelfHostLintDoesNotHitFfiCdef(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/bin/compile.php';

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_SELFHOST_AOT'] = '1';
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-l', $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        $this->assertSame(0, $exitCode, 'bin/compile.php self-host lint: '.$stderr);
        $this->assertStringNotContainsString('FFI::cdef', $stderr);
        $this->assertStringNotContainsString('undefined static method FFI::', $stderr);
    }

    /** bin/compile.php cli_driver guard — fold bundle constant outside spine smoke (#2600). */
    public function testCliDriverLibSpineConstantFoldsForBinCompileDriver(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function bootstrap_lib_spine_branch(): string {
    if (defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE) {
        return 'spine';
    }
    return 'driver';
}
echo bootstrap_lib_spine_branch(), "\n";
PHP;
        $stderr = $this->compileSource($source, 'PHP_COMPILER_LIB_SPINE_SMOKE cli_driver fold');
        $this->assertStringNotContainsString(
            'Unknown constant fetch: PHP_COMPILER_LIB_SPINE_SMOKE',
            $stderr
        );
    }

    /** Issue #16828: global assign inside foreach/try after early return must AOT-compile (llvm-env pattern). */
    public function testGlobalForeachTryAfterEarlyReturnCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/test/fixtures/aot/compile-only/global_foreach_try_after_early_return.php';
        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_global_foreach_try_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        if (is_file($outfile)) {
            @unlink($outfile);
        }
        $this->assertSame(0, $exitCode, 'global foreach/try AOT compile: '.$stderr);
        $this->assertStringNotContainsString('Cannot assignOperandValue to a value', $stderr);
        $this->assertStringNotContainsString('Cannot assign to a value', $stderr);
    }

    /** bin/vm.php {main} guard — defined()&&const phi must not throw assignOperand (#1492). */
    public function testBinVmTopLevelSpineGuardAssignOperandCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
if (
    !(defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE)
    && !(\function_exists('php_compiler_cli_should_skip_entry_driver') && php_compiler_cli_should_skip_entry_driver())
) {
    echo "driver\n";
}
PHP;
        $stderr = $this->compileSourceAllowFailure($source, 'bin/vm.php top-level spine guard');
        $this->assertStringNotContainsString('Cannot assign to a value', $stderr);
    }

    /** SodiumJitHelper memcmp guard — bool phi + string offset in nested JIT (#16828). */
    public function testSodiumMemcmpGuardAssignOperandValueCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function sodium_memcmp_guard(string $string1, string $string2): int {
    if (\strlen($string1) !== \strlen($string2)) {
        return -1;
    }
    if (\function_exists('sodium_memcmp')) {
        return \sodium_memcmp($string1, $string2);
    }
    $sum = 0;
    for ($i = 0; $i < \strlen($string1); ++$i) {
        $sum += \ord($string1[$i]);
    }
    return $sum;
}
PHP;
        $stderr = $this->compileSourceAllowFailure($source, 'sodium memcmp guard bool phi');
        $this->assertStringNotContainsString('Cannot assign to a value', $stderr);
        $this->assertStringNotContainsString(
            'Array offset access requires hashtable or boxed array',
            $stderr
        );
    }

    /** TimezoneAbbreviationsData nested array literal via require — compile (#16866). */
    public function testTimezoneAbbreviationsDataRequireCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $dataPath = $repoRoot.'/ext/standard/TimezoneAbbreviationsData.php';
        $source = '<?php
declare(strict_types=1);
$tz = require '.var_export($dataPath, true).';
echo count($tz), "\n";
';
        $stderr = $this->compileSourceAllowFailure($source, 'timezone abbreviations require');
        $this->assertStringNotContainsString(
            'Array offset access requires hashtable or boxed array',
            $stderr
        );
        $this->assertStringNotContainsString('loadHashtablePointer on native string', $stderr);
    }

    /**
     * Mini-spine IncludeHelper path with REMAP=0 must not hit Undefined constant "defined" (#29111).
     */
    public function testConstFetchSpineRemapOffCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $entry = $repoRoot.'/test/selfhost/issue_29111_const_fetch_spine/main.php';
        $this->assertFileExists($entry);

        $outfile = tempnam(sys_get_temp_dir(), 'issue29111_aot_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        $env['PHP_COMPILER_INCLUDE_SCOPE_REMAP'] = '0';

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $entry]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($compile);
        $combined = $stdout.$stderr;
        $this->assertStringNotContainsString('Undefined constant "defined"', $combined);
        $this->assertStringNotContainsString('Undefined constant \'defined\'', $combined);
        $this->assertSame(0, $code, $combined);
        $this->assertFileExists($outfile);
        @unlink($outfile);
    }

    /** Self-host AOT: `new Runtime()` must not segfault LLVM 9 (#2600). */
    public function testSelfHostAotNewRuntimeCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function bootstrap_new_runtime(): int {
    new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    return 0;
}
echo bootstrap_new_runtime(), "\n";
PHP;
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_SELFHOST_AOT'] = '1';
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $tmpPhp = tempnam(sys_get_temp_dir(), 'bootstrap_aot_runtime_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_runtime_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        @unlink($sourcePath);
        if (is_file($outfile)) {
            @unlink($outfile);
        }
        $stderr = trim($stderr !== false ? $stderr : '');
        if (139 === $exitCode) {
            $this->markTestSkipped('LLVM 9 segfault during self-host Runtime ctor (#2600).');
        }
        $this->assertSame(0, $exitCode, 'self-host new Runtime AOT compile: '.$stderr);
    }

    /** Self-host M5: bin/compile.php must AOT-compile under PHP_COMPILER_SELFHOST_AOT=1 (#2697). */
    public function testSelfHostAotBinCompilePhpCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/bin/compile.php';

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_SELFHOST_AOT'] = '1';
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_bin_compile_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        if (is_file($outfile)) {
            @unlink($outfile);
        }

        $this->assertSame(0, $exitCode, 'self-host bin/compile.php AOT compile: '.$stderr);
        $this->assertStringNotContainsString('LinkerProcessPolyfill::run()', $stderr);
        $this->assertStringNotContainsString('compile-time literal path', $stderr);
    }

    public function testBinCompilePhpUsesLiteralLinkerProcessPolyfillInclude(): void
    {
        $compile = (string) file_get_contents(dirname(__DIR__, 2).'/bin/compile.php');
        $this->assertStringContainsString("require_once 'lib/AOT/LinkerProcessPolyfill.php'", $compile);
        $this->assertStringNotContainsString("__DIR__.'/../lib/AOT/LinkerProcessPolyfill.php'", $compile);
    }

    public function testBootstrapAotConcatIntoValueBoxThenStrposRuns(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/test/bootstrap-aot/stdlib_string_strpos.php';

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_BOOTSTRAP_AOT_LINK'] = '1';
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_strcmp_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        $this->assertSame(0, $exitCode, 'bootstrap strpos AOT compile: '.$stderr);
        $this->assertFileExists($outfile);

        $runArgv = [$outfile];
        $run = proc_open($runArgv, $descriptorSpec, $runPipes, $repoRoot, $env);
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        $runStderr = stream_get_contents($runPipes[2]);
        fclose($runPipes[2]);
        $runExit = proc_close($run);
        @unlink($outfile);

        $this->assertSame(0, $runExit, trim($runStderr !== false ? $runStderr : ''));
        $this->assertSame("1\n", $stdout !== false ? $stdout : '');
    }

    public function testBootstrapAotRtrimOnConcatValueBoxCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = $repoRoot.'/test/bootstrap-aot/rtrim_value_box.php';

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_BOOTSTRAP_AOT_LINK'] = '1';
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_rtrim_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $stderr = trim($stderr !== false ? $stderr : '');
        $this->assertSame(0, $exitCode, 'bootstrap rtrim VALUE-box AOT compile: '.$stderr);
        $this->assertStringNotContainsString('Factory::basicBlock()', $stderr);
        $this->assertStringNotContainsString('Module verification failed', $stderr);
        $this->assertFileExists($outfile);

        $runArgv = [$outfile];
        $run = proc_open($runArgv, $descriptorSpec, $runPipes, $repoRoot, $env);
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        $runStderr = stream_get_contents($runPipes[2]);
        fclose($runPipes[2]);
        $runExit = proc_close($run);
        @unlink($outfile);

        $this->assertSame(0, $runExit, trim($runStderr !== false ? $runStderr : ''));
        $this->assertSame("lib/JIT.php\n", $stdout !== false ? $stdout : '');
    }

    private function skipUnlessLlvmReady(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                LlvmToolchain::readyFailureReason()
                ?? 'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private function assertCompileExitZero(string $source, string $label): void
    {
        $stderr = $this->compileSource($source, $label);
        $this->assertStringNotContainsString('Undefined class constant: MODE_AOT', $stderr);
    }

    private function compileSource(string $source, string $label): string
    {
        $tmpPhp = tempnam(sys_get_temp_dir(), 'bootstrap_aot_src_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        @unlink($sourcePath);
        if (is_file($outfile)) {
            @unlink($outfile);
        }
        $stderr = trim($stderr !== false ? $stderr : '');
        $this->assertSame(0, $exitCode, $label.' AOT compile failed: '.$stderr);

        return $stderr;
    }

    /** @return string combined stderr (and fatal message on stdout when PHP prints there) */
    private function compileSourceAllowFailure(string $source, string $label): string
    {
        $tmpPhp = tempnam(sys_get_temp_dir(), 'bootstrap_aot_src_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($compile);
        @unlink($sourcePath);
        if (is_file($outfile)) {
            @unlink($outfile);
        }

        return trim(
            ($stderr !== false ? $stderr : '')
            ."\n"
            .($stdout !== false ? $stdout : '')
        );
    }
}
