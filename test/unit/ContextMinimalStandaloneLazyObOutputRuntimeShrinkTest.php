<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on ObOutputRuntime (#34695 / peer #34642).
 *
 * Thin AOT hello-world must not eagerly NestedJIT ob_* ABI; ValueEchoHelper /
 * ValueEchoRuntime / JIT ECHO·PRINT ensureLinked lazily (#32122 .1 mint class).
 *
 * php-src: ext/standard/output.c — php_output_* / echo flush path
 */
final class ContextMinimalStandaloneLazyObOutputRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerObOutput(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34695', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly ObOutputRuntime (#34695)'
        );

        // Essentials for thin argv / getenv / bridges stay.
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'EnvLocalRuntime::ensureLinked($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
            'ExceptionBridge::ensureStandaloneBodies($this)',
            'ErrorBridge::ensureStandaloneBodies($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34695)");
        }

        // Full standalone: ObOutput before ValueEcho dropped; ValueEchoRuntime::ensureLinked
        // pulls ObOutput (peer #34642).
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullSlice = substr($context, $fullPos, 2500);
        $this->assertStringNotContainsString(
            "Builtin\\ObOutputRuntime::ensureLinked(\$this);\n            Builtin\\ValueEchoRuntime::ensureLinked(\$this)",
            $fullSlice,
            'ensureFull must not always-on ObOutput immediately before ValueEcho (#34695)'
        );
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullSlice);
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'lib/JIT/ValueEchoHelper.php' => 'ObOutputRuntime::ensureLinked',
            'lib/JIT/Builtin/ValueEchoRuntime.php' => 'ObOutputRuntime::ensureLinked',
            'lib/JIT.php' => 'ObOutputRuntime::ensureLinked($this->context)',
            'lib/JIT/Builtin/StringFormat.php' => 'ObOutputRuntime::ensureLinked($context)',
            'lib/JIT/Builtin/ScriptExit.php' => 'ObOutputRuntime::ensureLinked($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34695)');
        }
    }

    public function testNoNewRuntimeCForMinimalObOutputLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/ob_output.c',
            'must not add ob_output.c for #34695 — PHP JIT bridges only'
        );
    }
}
