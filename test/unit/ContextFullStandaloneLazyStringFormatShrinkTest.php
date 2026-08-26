<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StringFormat
 * (#35130 / peer #35127 / #32921).
 *
 * Full standalone must not NestedJIT __compiler_sprintf / __compiler_printf /
 * __compiler_number_format during init (#32122 .1 mint class). Call sites already
 * implementIfDeclared / ensureLinked before lookup.
 */
final class ContextFullStandaloneLazyStringFormatShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerStringFormatNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35130', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringFormat::ensureStandaloneBodies($this)',
            'StringFormat::ensureLinked($this)',
            'StringFormat::implement($this)',
            'StringFormat::implementIfDeclared($this',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35130)'
            );
        }

        // ValueEcho deferred to call sites (#35143); CliArgv/#35133 + SuperglobalRefresh/#35137 at compileToFile.
        $this->assertStringNotContainsString(
            'ValueEchoRuntime::ensureLinked($this)',
            $fullBody,
            'ValueEcho deferred to emitValue/helpers (#35143)'
        );
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'CliArgv deferred to compileToFile (#35133)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitSprintf.php' => 'StringFormat::implementIfDeclared',
            'ext/standard/JitPrintf.php' => 'StringFormat::implementIfDeclared',
            'ext/standard/JitNumberFormat.php' => 'StringFormat::implementIfDeclared',
            'ext/standard/JitFprintf.php' => 'StringFormat::implementIfDeclared',
            'ext/standard/JitVfprintf.php' => 'StringFormat::implementIfDeclared',
            'ext/standard/JitVsprintf.php' => 'StringFormat::ensureLinked',
            'lib/JIT/Builtin/PregMatchRuntime.php' => 'StringFormat::ensureLinked',
            'lib/JIT/Builtin/StringOpendir.php' => 'StringFormat::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#35130)');
        }
    }

    public function testStringFormatDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFormat.php');
        $this->assertStringContainsString('#35130', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testNoNewRuntimeCForFullStringFormatLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/sprintf.c',
            'must not add sprintf.c for #35130 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_sprintf.c',
            'must not add phpc_sprintf.c for #35130'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/number_format.c',
            'must not add number_format.c for #35130'
        );
    }
}
