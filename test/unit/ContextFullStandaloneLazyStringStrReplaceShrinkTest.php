<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StringStrReplace
 * (#35160 / peer #35143 ValueEcho / #35130 StringFormat / #23970 helper-runtime).
 *
 * Full standalone must not NestedJIT phpc_str_replace during init (#32122 .1 mint class).
 * JitStrReplace / invoke already ensureLinked before lookup; cache-on NestedJIT can still
 * ensureLinked (bin/compile.php forces HELPER_RUNTIME_O for skip-bundle compile_driver).
 */
final class ContextFullStandaloneLazyStringStrReplaceShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerStringStrReplaceNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35160', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringStrReplace::ensureStandaloneBodies($this)',
            'StringStrReplace::ensureLinked($this)',
            'StringStrReplace::invoke($this',
            'StringStrReplace::implementReplace($this',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35160)'
            );
        }

        // Prior always-on shrinks stay deferred.
        $this->assertStringNotContainsString(
            'ValueEchoRuntime::ensureLinked($this)',
            $fullBody,
            'ValueEcho deferred to emitValue/helpers (#35143)'
        );
        $this->assertStringNotContainsString(
            'StringFormat::ensureStandaloneBodies($this)',
            $fullBody,
            'StringFormat deferred to JitSprintf/Printf (#35130)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrReplace.php');
        $this->assertStringContainsString('#35160', $runtime);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $runtime);
        $invokePos = strpos($runtime, 'public static function invoke');
        $this->assertNotFalse($invokePos);
        $invokeNext = strpos($runtime, 'private static function ', $invokePos + 10);
        $invokeBody = false === $invokeNext
            ? substr($runtime, $invokePos)
            : substr($runtime, $invokePos, $invokeNext - $invokePos);
        $this->assertStringContainsString(
            'self::ensureLinked($context)',
            $invokeBody,
            'invoke must ensureLinked before phpc_str_replace lookup (#35160)'
        );

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrReplace.php');
        $this->assertStringContainsString('StringStrReplace::invoke', $jit);

        // #23970: skip-bundle still enables helper-runtime .o so NestedJIT can ensureLinked.
        $compile = (string) file_get_contents(__DIR__.'/../../bin/compile.php');
        $this->assertStringContainsString('#23970', $compile);
        $this->assertStringContainsString('PHP_COMPILER_HELPER_RUNTIME_O=1', $compile);
    }

    public function testNoNewRuntimeCForFullStringStrReplaceLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/str_replace.c',
            'must not add str_replace.c for #35160 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_str_replace.c',
            'must not add phpc_str_replace.c for #35160'
        );
    }
}
