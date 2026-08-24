<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on Process/Posix/StreamSocket/Ftok ensureLinked
 * (#34333 / peer #34327).
 *
 * Call-site Jit* / Runtime::invoke owners link lazily (getNamedFunction first)
 * so hello-world and other scripts that never touch these builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyProcessPosixStreamSocketFtokRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerProcessPosixStreamSocketFtokEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34333', $type);
        foreach ([
            'ProcessRuntime::ensureLinked($this->context)',
            'ProcessOpen::ensureLinked($this->context)',
            'StreamSocketPair::ensureLinked($this->context)',
            'StreamSocketGetNameRuntime::ensureLinked($this->context)',
            'StreamSocketAccept::ensureLinked($this->context)',
            'FtokRuntime::ensureLinked($this->context)',
            'PosixGetpidJit::ensureLinked($this->context)',
            'PosixGetppidJit::ensureLinked($this->context)',
            'PosixGetuidJit::ensureLinked($this->context)',
            'PosixGeteuidJit::ensureLinked($this->context)',
            'PosixGetgidJit::ensureLinked($this->context)',
            'PosixGetegidJit::ensureLinked($this->context)',
            'PosixSetuidJit::ensureLinked($this->context)',
            'PosixSetgidJit::ensureLinked($this->context)',
            'PosixSeteuidJit::ensureLinked($this->context)',
            'PosixSetegidJit::ensureLinked($this->context)',
            'PosixSetsidJit::ensureLinked($this->context)',
            'PosixSetpgidJit::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34333)'
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34333 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitShellExec.php' => 'ProcessRuntime::ensureLinked',
            'ext/standard/JitEscapeshellarg.php' => 'ProcessRuntime::ensureLinked',
            'ext/standard/JitEscapeshellcmd.php' => 'ProcessRuntime::ensureLinked',
            'ext/standard/JitProcOpen.php' => 'ProcessOpen::ensureLinked',
            'ext/standard/JitProcClose.php' => 'ProcessOpen::ensureLinked',
            'ext/standard/JitProcTerminate.php' => 'ProcessOpen::ensureLinked',
            'ext/standard/JitProcGetStatus.php' => 'ProcessOpen::ensureLinked',
            'ext/standard/stream_socket_pair.php' => 'StreamSocketPair::ensureLinked',
            'ext/standard/JitStreamSocketGetName.php' => 'StreamSocketGetNameRuntime::ensureLinked',
            'ext/standard/JitStreamSocketAccept.php' => 'StreamSocketAcceptRuntime::ensureLinked',
            'lib/JIT/Builtin/FtokRuntime.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixGetpidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixGetppidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixGetuidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixGeteuidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixGetgidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixGetegidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixSetuidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixSetgidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixSeteuidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixSetegidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixSetsidJit.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/PosixSetpgidJit.php' => 'self::ensureLinked($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34333)');
        }
    }

    public function testNoNewRuntimeCForLazyProcessPosixStreamSocketFtokAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_shell_exec.c',
            'phpc_proc_open.c',
            'phpc_stream_socket.c',
            'phpc_ftok.c',
            'phpc_posix_getpid.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34333)');
        }
    }
}
