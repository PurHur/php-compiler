<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for compact()/extract() double locals (#4094).
 *
 * php-src: ext/standard/basic_functions.c php_compact(), php_extract()
 *
 * MCJIT execute remains unstable (jit-runtime-probe #98); this guards IR lowering.
 *
 * @group llvm
 */
final class CompactExtractFloatJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — compact/extract float JIT compile test needs LLVM (#4094)');
        }
    }

    public function testCompactExtractFloatModuleVerify(): void
    {
        $runtime = new Runtime();
        foreach (
            [
                [$this->phptFixtureCode('compact_float_jit.phpt'), 'compact_float_jit.phpt'],
                [
                    <<<'PHP'
<?php
$a = ['x' => 1.5];
echo json_encode($a);
PHP,
                    'array_string_key_double.php',
                ],
            ] as [$code, $filename]
        ) {
            $block = $runtime->parseAndCompile($code, $filename);
            $this->assertNotNull($block, $filename);
            $runtime->jitCompileBlock($block);
        }

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
