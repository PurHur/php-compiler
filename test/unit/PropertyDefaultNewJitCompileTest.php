<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM lowering smoke for promoted-parameter `new` default init (#3391, #6652).
 *
 * Instance/static property defaults reject `new` on all profiles (#21493); constructor promoted
 * parameters lower runtimePropertyNewDefaults (Zend zend_objects.c / zend_compile.c).
 * Full module verify + AOT behaviour: {@see PromotedParamNewDefault6652Test}.
 *
 * @group llvm
 */
final class PropertyDefaultNewJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — property default new JIT compile test needs LLVM (#3391)');
        }
    }

    public function testPropertyDefaultNewJitLoweringSmoke(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            $this->fixtureCode('promoted_param_new_default.phpt'),
            'promoted_param_new_default.phpt'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    private function fixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
    }
}
