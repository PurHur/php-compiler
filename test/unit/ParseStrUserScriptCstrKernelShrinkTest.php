<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** parse_str user-script AOT C-string kernel quarantined in ext/standard (#19500, #18855). */
final class ParseStrUserScriptCstrKernelShrinkTest extends TestCase
{
    public function testUserScriptCstrLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ParseStrRuntimeUserScriptCstr.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitParseStrUserScriptCstrKernel.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('JitParseStrUserScriptCstrKernel', $builtin);
        $this->assertStringNotContainsString('ParseStrRuntimeUserScriptCstr', $builtin);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitParseStrUserScriptCstrKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitParseStrUserScriptCstrKernel', $kernel);
        $this->assertStringContainsString('__phpc_parse_str_parse_delimited_pairs', $kernel);
        $this->assertStringContainsString('ensureSubhelpers', $kernel);
        // strtok result must be stored before branching to the pair loop (#29001).
        $this->assertStringContainsString(
            'Store strtok result before branching',
            $kernel,
            'pairSlot store-before-branch guard comment (#29001)'
        );
    }

    public function testSpineBundleIncludesParseStrUserScriptCstrKernelNotBuiltin(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitParseStrUserScriptCstrKernel.php', $spine);
        $this->assertStringContainsString('ParseStrRuntime.php', $spine);
        $this->assertStringNotContainsString('ParseStrRuntimeUserScriptCstr.php', $spine);
    }
}
