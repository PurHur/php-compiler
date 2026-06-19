<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for PHP 8.3 typed class constants (#4900).
 *
 * php-src: Zend/zend_compile.c (typed const validation), zend_constants.c
 *
 * MCJIT execute remains gated by jit-runtime-probe (#98).
 *
 * @group llvm
 */
final class JitTypedClassConstCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — typed class const JIT compile test needs LLVM (#4900)');
        }
    }

    /**
     * @dataProvider typedClassConstPhptProvider
     */
    public function testTypedClassConstModuleVerify(string $fixture): void
    {
        $runtime = new Runtime();
        $code = $this->phptFixtureCode($fixture);
        $block = $runtime->parseAndCompile($code, $fixture);
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    public static function typedClassConstPhptProvider(): iterable
    {
        foreach (
            [
                'typed_class_const.phpt',
                'typed_class_const_float_int.phpt',
                'typed_class_constant.phpt',
                'typed_enum_class_const.phpt',
                'interface_typed_const.phpt',
            ] as $fixture
        ) {
            yield $fixture => [$fixture];
        }
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
