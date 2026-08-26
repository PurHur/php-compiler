<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StringGetenv /
 * StringGetenvAll (#35127 / peer #35113 / #32665).
 *
 * Full standalone must not NestedJIT __compiler_getenv / __compiler_getenv_all during
 * init (#32122 .1 mint class). Call sites already ensureLinked before lookup.
 */
final class ContextFullStandaloneLazyGetenvShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerGetenvNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35127', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringGetenv::ensureStandaloneBodies($this)',
            'StringGetenvAll::ensureStandaloneBodies($this)',
            'StringGetenv::ensureLinked($this)',
            'StringGetenvAll::ensureLinked($this)',
            'StringGetenv::implement($this)',
            'StringGetenvAll::implement($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35127)'
            );
        }

        // Still links echo; CliArgv/#35133 + SuperglobalRefresh/#35137 at compileToFile.
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $env = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString(
            'StringGetenvAll::ensureLinked($context)',
            $env,
            'JitEnv::getenvAll must ensure lazily (#35127)'
        );
        $this->assertStringContainsString(
            'StringGetenv::ensureLinked($context)',
            $env,
            'JitEnv::getenv must ensure lazily (#35127)'
        );
    }

    public function testStringGetenvDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('#35127', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testStringGetenvAllDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('#35127', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testNoNewRuntimeCForFullGetenvLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/getenv.c',
            'must not add getenv.c for #35127 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_getenv.c',
            'must not add phpc_getenv.c for #35127'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/getenv_all.c',
            'must not add getenv_all.c for #35127'
        );
    }
}
