<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of Utf8Latin1 /
 * RewriteVars / Define / Strspn / FileGetContents / Readfile (#35089 / peers #34578 / #35086).
 *
 * Full standalone must not NestedJIT those ABIs during init (#32122 .1 mint class).
 * Call sites already ensureLinked before lookup.
 */
final class ContextFullStandaloneLazyStdlibRuntimeShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerStdlibNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35089', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringUtf8Latin1::ensureStandaloneBodies($this)',
            'RewriteVarsRuntime::ensureStandaloneBodies($this)',
            'DefineRuntime::ensureStandaloneBodies($this)',
            'StringStrspn::ensureStandaloneBodies($this)',
            'StringFileGetContents::ensureStandaloneBodies($this)',
            'StringReadfile::ensureStandaloneBodies($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35089)'
            );
        }

        // Still links echo / argv / refresh used by standalone main.
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringContainsString('CliArgvRuntime::ensureStandaloneBodies($this)', $fullBody);
        $this->assertStringContainsString('SuperglobalRefreshRuntime::ensureStandaloneBodies($this)', $fullBody);
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitUtf8Latin1.php' => 'StringUtf8Latin1::ensureLinked',
            'ext/standard/JitDefine.php' => 'DefineRuntime::ensureLinked',
            'ext/standard/JitFileGetContents.php' => 'StringFileGetContents::ensureLinked',
            'ext/standard/readfile.php' => 'StringReadfile::ensureLinked',
            'ext/standard/SpnJitLowering.php' => 'StringStrspn::ensureLinked',
            'lib/JIT/Builtin/RewriteVarsRuntime.php' => 'self::ensureLinked($context)',
            'lib/JIT/BootstrapCompileSmokeM3Emit.php' => 'StringFileGetContents::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#35089)');
        }
    }

    public function testNoNewRuntimeCForFullStdlibLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'file_get_contents.c',
            'readfile.c',
            'utf8_latin1.c',
            'define_runtime.c',
            'strspn.c',
            'rewrite_vars.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #35089 — PHP JIT bridges only"
            );
        }
    }
}
