<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\OpCode;
use PHPCompiler\VM\GeneratorIteratorJitHelper;
use PHPCompiler\VM\GeneratorJitHelper;
use PHPCompiler\VM\GeneratorYieldFromJitHelper;
use PHPCompiler\VM\VmGenerator;
use PHPUnit\Framework\TestCase;

/** Generator compile-time CFG + LLVM shrink lives in VM PHP SSOT (#10105). */
final class GeneratorJitHelperTest extends TestCase
{
    public function testGeneratorHelperDelegatesCompileTimeAnalysisToVmHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/GeneratorHelper.php');
        $this->assertStringContainsString('GeneratorJitHelper', $source);
        $this->assertStringContainsString('GeneratorJitHelper::collectResumePoints', $source);
        $this->assertStringNotContainsString('walkBlockForResumePoints', $source);
        $this->assertStringContainsString('VmGenerator::ensureJitTypes', $source);
        $this->assertStringContainsString('GeneratorYieldFromJitHelper::emitYieldFromPoint', $source);
        $this->assertStringContainsString('GeneratorIteratorJitHelper::emitYieldPoint', $source);
        $this->assertLessThan(500, substr_count($source, "\n"), 'GeneratorHelper should stay a thin delegate');
    }

    public function testVmGeneratorSharesPropertyConstants(): void
    {
        $this->assertSame(GeneratorHelper::TARGET_PROPERTY, VmGenerator::TARGET_PROPERTY);
        $this->assertSame(GeneratorHelper::STATE_PROPERTY, VmGenerator::STATE_PROPERTY);
    }

    public function testYieldFromJitHelperClassExists(): void
    {
        $this->assertTrue(class_exists(GeneratorYieldFromJitHelper::class));
        $this->assertTrue(class_exists(GeneratorIteratorJitHelper::class));
    }

    public function testPrefixSegmentSafeForYieldFromInitViaPublicApi(): void
    {
        $block = new Block(null);
        $block->opCodes[] = new OpCode(OpCode::TYPE_ASSIGN);
        $block->opCodes[] = new OpCode(OpCode::TYPE_YIELD);
        $block->nOpCodes = 2;

        $this->assertTrue(GeneratorHelper::prefixSegmentSafeForYieldFromInit($block, 0, 1));
        $this->assertFalse(GeneratorHelper::prefixSegmentSafeForYieldFromInit($block, 0, 2));
    }

    public function testForeachByRefErrorConstantShared(): void
    {
        $this->assertSame(
            GeneratorHelper::FOREACH_GENERATOR_BYREF_ERROR,
            GeneratorJitHelper::FOREACH_GENERATOR_BYREF_ERROR
        );
    }

    public function testCreatorResumeName(): void
    {
        $context = $this->createMock(Context::class);
        $context->generatorCreators = ['inner' => 'resume_inner'];

        $this->assertSame('resume_inner', GeneratorJitHelper::creatorResumeName($context, 'inner'));
        $this->assertSame('resume_inner', GeneratorJitHelper::creatorResumeName($context, 'Ns\\Inner'));
        $this->assertNull(GeneratorJitHelper::creatorResumeName($context, 'missing'));
    }

    public function testLlvmInternalNameSanitizesReserved(): void
    {
        $this->assertSame('php_user_main', GeneratorJitHelper::llvmInternalName('main'));
        $this->assertSame('my_func', GeneratorJitHelper::llvmInternalName('my_func'));
    }
}
