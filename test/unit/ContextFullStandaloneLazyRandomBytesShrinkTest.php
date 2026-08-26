<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StringRandomBytes
 * (#35113 / peer #34578 / #35065).
 *
 * Full standalone must not NestedJIT __compiler_random_bytes during init
 * (#32122 .1 mint class). Call sites already ensureLinked before lookup.
 */
final class ContextFullStandaloneLazyRandomBytesShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerRandomBytesNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35113', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringRandomBytes::implement($this)',
            'StringRandomBytes::ensureStandaloneBodies($this)',
            'StringRandomBytes::ensureLinked($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35113)'
            );
        }

        // Still links echo / refresh (#35130 StringFormat + #35133 CliArgv deferred).
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringContainsString('SuperglobalRefreshRuntime::ensureStandaloneBodies($this)', $fullBody);
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitRandomBytes.php' => 'StringRandomBytes::ensureLinked($context)',
            'lib/JIT/ArrayRandLlvm.php' => 'StringRandomBytes::ensureLinked($context)',
            'lib/JIT/Builtin/SessionCreateIdRuntime.php' => 'StringRandomBytes::ensureLinked($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#35113)');
        }
    }

    public function testStringRandomBytesDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php');
        $this->assertStringContainsString('#35113', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testNoNewRuntimeCForFullRandomBytesLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/random_bytes.c',
            'must not add random_bytes.c for #35113 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_random_bytes.c',
            'must not add phpc_random_bytes.c for #35113'
        );
    }
}
