<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_substitute_character() via MbSubstituteCharacterJitHelper (#35263 leftover of #13100).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_substitute_character)
 *
 * @group llvm
 * @group aot
 */
final class MbSubstituteCharacterRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_substitute_character_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbSubstituteCharacterJitHelper.php');
        $this->assertStringContainsString('function canonicalizeStringArgv', $helper);
        $this->assertStringContainsString('function canonicalizeLongArgv', $helper);
        $this->assertStringContainsString('CODE_NONE', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbSubstituteCharacterRuntime.php');
        $this->assertStringContainsString('canonicalizeStringHelper', $runtime);
        $this->assertStringContainsString('G_SUBST_CODE', $runtime);
        $this->assertStringContainsString('MbSubstituteCharacterJitHelper::canonicalizeStringArgv', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbSubstituteCharacter.php');
        $this->assertStringContainsString('MbSubstituteCharacterRuntime', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_substitute_character.php');
        $this->assertStringContainsString('JitMbSubstituteCharacter::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_substitute_character() JIT setter is not supported',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_substitute_character.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_substitute_character.c');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_subst_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
        } finally {
            chdir($cwd);
            if (is_file($bin)) {
                @unlink($bin);
            }
        }

        return implode("\n", $out);
    }
}
