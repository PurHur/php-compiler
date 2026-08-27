<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::compileToFile always-on ErrorBridge::ensureLinked / registerDeclarations
 * (#35443 / peer #35099 ensureFull / #34769 ensureMinimal).
 *
 * Thin hello-world must not NestedJIT AssertionErrorRaise during {main} prologue
 * (#32122 .1 mint class). emitClear / emitAbort already ensureLinked before lookup.
 */
final class ContextCompileToFileLazyErrorBridgeRuntimeShrinkTest extends TestCase
{
    public function testCompileToFileDropsEagerErrorBridgeEnsure(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35443', $context);
        $pos = strpos($context, 'public function compileToFile');
        $this->assertNotFalse($pos);
        $end = strpos($context, 'Progress::noteFunction(\'jit_context_compile_common_begin\')', $pos);
        $this->assertNotFalse($end);
        $body = substr($context, $pos, $end - $pos);

        foreach ([
            'ErrorBridge::ensureLinked($this)',
            'ErrorBridge::registerDeclarations($this)',
            'ErrorBridge::ensureStandaloneBodies($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'compileToFile must not eagerly '.$forbidden.' (#35443)'
            );
        }

        $this->assertStringContainsString(
            'ErrorBridge::emitClearForStandaloneMain($this)',
            $body,
            'thin/full {main} still clears pending Error (#23665 / #35443)'
        );
        $this->assertStringContainsString(
            'ErrorBridge::emitAbortIfPendingForStandaloneMain($this)',
            $body,
            'thin/full {main} still aborts pending Error (#23665 / #35443)'
        );
    }

    public function testEmitClearAbortSelfEnsure(): void
    {
        foreach ([
            'lib/JIT/Builtin/ErrorRaise.php',
            'lib/JIT/Builtin/ReadonlyRaise.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            foreach (['emitClearForStandaloneMain', 'emitAbortIfPendingForStandaloneMain'] as $method) {
                $pos = strpos($source, 'public static function '.$method);
                $this->assertNotFalse($pos, $rel.' '.$method);
                $next = strpos($source, 'public static function ', $pos + 10);
                $body = false === $next
                    ? substr($source, $pos)
                    : substr($source, $pos, $next - $pos);
                $this->assertStringContainsString(
                    'self::ensureLinked($context)',
                    $body,
                    $rel.'::'.$method.' must ensureLinked (#35443)'
                );
            }
        }
    }

    public function testNoNewRuntimeC(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertDirectoryExists($runtimeDir);
        $cFiles = glob($runtimeDir.'/*.c') ?: [];
        $this->assertSame([], $cFiles, 'lib/AOT/runtime must stay empty of *.c (#35443)');
    }
}
