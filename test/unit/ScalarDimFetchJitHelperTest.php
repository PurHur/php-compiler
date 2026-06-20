<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ScalarDimFetchJitHelper;
use PHPUnit\Framework\TestCase;

/** Scalar dim fetch warning SSOT via ScalarDimFetchJitHelper (#10271). */
final class ScalarDimFetchJitHelperTest extends TestCase
{
    public function testWarningMessageMatchesErrorReporterForScalarTypes(): void
    {
        $cases = [
            [JitVariable::TYPE_NULL, 'null'],
            [JitVariable::TYPE_NATIVE_BOOL, 'bool'],
            [JitVariable::TYPE_NATIVE_LONG, 'int'],
            [JitVariable::TYPE_NATIVE_DOUBLE, 'float'],
        ];
        foreach ($cases as [$jitType, $label]) {
            self::assertSame(
                ErrorReporter::arrayOffsetOnNonContainerMessage($label),
                ScalarDimFetchJitHelper::warningMessageForJitType($jitType)
            );
        }
    }

    public function testScalarDimFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/ScalarDimFetchJitHelper.php');
        $this->assertStringContainsString('emitWarningForJitType', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
    }
}
