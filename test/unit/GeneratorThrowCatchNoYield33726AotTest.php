<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Generator::throw into catch that does not yield again (#33726 / re-#27518 / #35144).
 *
 * php-src: Zend/zend_generators.c — zend_generator_throw
 *
 * The structure assertions alone went green while AotTest failed (#35144): do not treat
 * source-shape checks as proof the binary matches Zend.
 */
final class GeneratorThrowCatchNoYield33726AotTest extends TestCase
{
    public function testBeginTryGeneratorResumeWiresMergeBodyBeforeDispatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('#33726', $source);
        $this->assertStringContainsString('emitGeneratorResumeComplete', $source);
        $this->assertStringContainsString('mergeBodyLlvmBb', $source);
        $begin = strpos($source, 'function beginTryGeneratorResume');
        $this->assertNotFalse($begin);
        $chunk = substr($source, $begin, 3500);
        $this->assertStringContainsString('emitGeneratorResumeComplete', $chunk);
        $this->assertStringContainsString('mergeBodyLlvmBb', $chunk);
        // Must not mark merge compiled without a body BB (the #33726 bug).
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*!\$context->compilingGeneratorResume\s*\)\s*\{\s*\$jit->compileIncludedAtEntry/',
            $chunk
        );
        // Empty merge closes via emitGeneratorResumeComplete — not compileIncludedAtEntry
        // (that would ret %__value__ into the i64 resume fn).
        $this->assertStringNotContainsString('compileIncludedAtEntry($func, $handler->mergeBlock, $mergeBodyBb)', $chunk);
    }

    public function testInjectPendingThrowKeepsPendingThrowRooted(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/GeneratorIteratorJitHelper.php');
        $fn = strpos($source, 'function emitInjectPendingThrow');
        $this->assertNotFalse($fn);
        $chunk = substr($source, $fn, 2800);
        $this->assertStringContainsString('#35144', $chunk);
        // writeNull before dispatch freed the Exception while ExceptionJitHelper held only an address.
        $this->assertStringNotContainsString(
            "lookupFunction('__value__writeNull')",
            $chunk
        );
    }

    public function testAotFixtureAndReproExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/test/fixtures/aot/cases/generator_throw_catch_no_yield.phpt');
        $this->assertFileExists($root.'/test/repro/issue_gen_throw_catch_no_yield.php');
        $fixture = (string) file_get_contents($root.'/test/fixtures/aot/cases/generator_throw_catch_no_yield.phpt');
        $this->assertStringContainsString('#33726', $fixture);
        $this->assertStringContainsString('--EXPECT--', $fixture);
        $this->assertStringContainsString("Cx\n", $fixture."\n");
    }
}
