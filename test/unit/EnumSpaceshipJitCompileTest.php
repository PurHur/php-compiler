<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for enum <=> JIT lowering (#4805).
 *
 * php-src: Zend/zend_enum.c (zend_compare_enum), zend_operators.c
 *
 * MCJIT execute remains gated by jit-runtime-probe (#98).
 *
 * @group llvm
 */
final class EnumSpaceshipJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum spaceship JIT compile test needs LLVM (#4805)');
        }
    }

    public function testEnumSpaceshipModuleVerify(): void
    {
        $runtime = new Runtime();
        $code = $this->phptFixtureCode('enum_spaceship_jit.phpt');
        $block = $runtime->parseAndCompile($code, 'enum_spaceship_jit.phpt');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    private function phptFixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
    }
}
