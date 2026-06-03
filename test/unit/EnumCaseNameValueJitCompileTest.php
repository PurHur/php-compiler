<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for enum case ->name / ->value property fetch (#4953).
 *
 * php-src: Zend/zend_enum.c — zend_enum_get_case_name / case object handlers.
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class EnumCaseNameValueJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum case name/value JIT compile test needs LLVM (#4953)');
        }
    }

    public function testEnumCaseNameValuePropertyFetchModuleVerify(): void
    {
        $code = $this->phptFixtureCode('enum_case_name_value.phpt');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_name_value.phpt');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertTrue(
            str_contains($bc, 'enum_case_prop_fetch_')
            || str_contains($bc, 'php_compiler_enum_case_jit_'),
            'Expected enum case singleton and/or runtime property fetch dispatch (#4953)'
        );
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
