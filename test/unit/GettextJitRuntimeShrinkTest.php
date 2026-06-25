<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** gettext JIT lowering via StringGettextJit — no LogicException stub in GettextFunction (#8625). */
final class GettextJitRuntimeShrinkTest extends TestCase
{
    public function testGettextFunctionDispatchesJitGettext(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/gettext/GettextFunction.php');
        $this->assertStringContainsString('JitGettext::gettext', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testStringGettextRuntimeDeclaresAbiHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGettextRuntime.php');
        $this->assertStringContainsString('__compiler_gettext', $source);
        $this->assertStringContainsString('__compiler_bindtextdomain', $source);
        $this->assertStringContainsString('__compiler_dngettext', $source);
        $this->assertStringContainsString('GettextJitHelper', $source);
    }

    public function testJitGettextPhpSourceParses(): void
    {
        $path = dirname(__DIR__, 2).'/ext/gettext/JitGettext.php';
        $output = [];
        $exitCode = 0;
        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $source = (string) file_get_contents($path);
        $this->assertStringNotContainsString('...$params, $ptr', $source);
    }

    public function testNoNewAotRuntimeCSources(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $cFiles = glob($runtimeDir.'/*.c') ?: [];
        sort($cFiles);
        $this->assertSame(
            [$runtimeDir.'/phpc_progress.c'],
            $cFiles,
            'gettext JIT must not add C runtime TUs'
        );
    }
}
