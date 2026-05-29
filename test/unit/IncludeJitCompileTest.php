<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for literal include/require JIT lowering (#475, #54).
 *
 * IR verify via {@see JIT\Context::compileCommon()} — no MCJIT link/execute.
 * MCJIT execute for includes is covered by compliance PHPT + {@see IncludeLiteralTest}
 * when {@see script/jit-runtime-probe.php} is green.
 *
 * @group llvm
 */
final class IncludeJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — include JIT compile test needs LLVM (#475)');
        }
    }

    public function testLiteralIncludeFixturesVerify(): void
    {
        $runtime = new Runtime();
        $languageRoot = $this->repoRoot.'/test/compliance/cases/language';
        $entries = [
            'include_two_file/entry.php',
            'include_dir_literal/entry.php',
            'include_scope_inherit/entry.php',
            'require_return/entry.php',
        ];
        foreach ($entries as $rel) {
            $path = realpath($languageRoot.'/'.$rel);
            $this->assertNotFalse($path, $rel);
            $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
            $this->assertNotNull($block, $rel);
            $this->assertNotEmpty(
                $block->literalIncludePaths,
                'expected compile-time literal include paths for '.$rel
            );
            $runtime->jitCompileBlock($block);
        }

        $dir = sys_get_temp_dir().'/phpc_inc_jit_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir) || is_dir($dir));
        file_put_contents($dir.'/helper.php', "<?php\nfunction answer(): int { return 42; }\n");
        $main = $dir.'/main.php';
        file_put_contents(
            $main,
            "<?php\ninclude ".var_export($dir.'/helper.php', true).";\necho answer();\n"
        );
        try {
            $block = $runtime->parseAndCompile((string) file_get_contents($main), $main);
            $this->assertNotNull($block);
            $this->assertNotEmpty($block->literalIncludePaths);
            $runtime->jitCompileBlock($block);
        } finally {
            @unlink($dir.'/helper.php');
            @unlink($main);
            @rmdir($dir);
        }

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
