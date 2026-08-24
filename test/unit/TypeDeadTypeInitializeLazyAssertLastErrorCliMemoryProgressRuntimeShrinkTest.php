<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on Assert/LastError/CliArgv/FunctionExists/Memory/ProgressNote
 * ensureLinked (#34463 / peer #34445).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyAssertLastErrorCliMemoryProgressRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerAssertLastErrorCliMemoryProgressEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34463', $type);
        foreach ([
            'LastErrorRuntime',
            'CliArgvRuntime',
            'FunctionExistsRuntime',
            'MemoryRuntime',
            'AssertFail',
            'AssertOptionsRuntime',
            'ProgressNoteRuntime',
        ] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.'(?<![A-Za-z0-9_])'.$class.'::ensureLinked\\(\\$this->context\\)/',
                $type,
                "Builtin\\Type::initialize must not eagerly {$class}::ensureLinked (#34463)"
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34463 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitErrorGetLast.php' => 'LastErrorRuntime::ensureLinked',
            'ext/standard/JitTriggerErrorKernel.php' => 'LastErrorRuntime::ensureLinked',
            'ext/standard/JitGetopt.php' => 'CliArgvRuntime::ensureLinked',
            'lib/JIT/CliArgvGlobalInit.php' => 'CliArgvRuntime::ensureLinked',
            'lib/JIT/Builtin/StringFunctionExists.php' => 'FunctionExistsRuntime::ensureLinked',
            'ext/standard/JitAssert.php' => 'AssertFail::ensureLinked',
            'ext/standard/JitAssertOptions.php' => 'AssertOptionsRuntime::ensureLinked',
            'ext/standard/JitMemory.php' => 'MemoryRuntime::getUsageValue',
            'lib/JIT/Builtin/MemoryRuntime.php' => 'self::ensureLinked($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34463)');
        }
        $jitPhp = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString(
            'MemoryRuntime::ensureLinked',
            $jitPhp,
            'JIT.php unset path must link MemoryRuntime (#34463)'
        );
        $this->assertStringContainsString(
            'ProgressNoteRuntime::ensureLinked',
            $jitPhp,
            'JIT.php must link ProgressNoteRuntime (#34463)'
        );
    }

    public function testNoNewRuntimeCForLazyAssertLastErrorCliMemoryProgressAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'assert_fail.c',
            'assert_options.c',
            'error_get_last.c',
            'getopt.c',
            'function_exists.c',
            'memory_get_usage.c',
            'progress_note.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34463)');
        }
    }
}
