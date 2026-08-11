<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ScalarDimFetchJitHelper;
use PHPUnit\Framework\TestCase;

/** Scalar dim fetch warning SSOT via ScalarDimFetchJitHelper (#10271 / #30053). */
final class ScalarDimFetchJitHelperTest extends TestCase
{
    public function testWarningMessageMatchesErrorReporterForScalarTypesUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $cases = [
                [JitVariable::TYPE_NULL, 'null'],
                [JitVariable::TYPE_NATIVE_LONG, 'int'],
                [JitVariable::TYPE_NATIVE_DOUBLE, 'float'],
                [ScalarDimFetchJitHelper::JIT_BOOL_TRUE, 'true'],
                [ScalarDimFetchJitHelper::JIT_BOOL_FALSE, 'false'],
            ];
            foreach ($cases as [$jitType, $label]) {
                self::assertSame(
                    ErrorReporter::arrayOffsetOnNonContainerMessage($label),
                    ScalarDimFetchJitHelper::warningMessageForJitType($jitType)
                );
                self::assertSame(
                    "Trying to access array offset on {$label}",
                    ScalarDimFetchJitHelper::warningMessageForJitType($jitType)
                );
            }
            self::assertSame(
                'Trying to access array offset on bool',
                ScalarDimFetchJitHelper::warningMessageForJitType(JitVariable::TYPE_NATIVE_BOOL)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testLegacyValueOfTypeUnderProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            self::assertSame(
                'Trying to access array offset on value of type int',
                ErrorReporter::arrayOffsetOnNonContainerMessage('int')
            );
            self::assertSame(
                'Trying to access array offset on value of type bool',
                ScalarDimFetchJitHelper::warningMessageForJitType(ScalarDimFetchJitHelper::JIT_BOOL_FALSE)
            );
            self::assertSame(
                'Trying to access array offset on value of type resource',
                ErrorReporter::arrayOffsetOnResourceMessage()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testScalarDimFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/ScalarDimFetchJitHelper.php');
        $this->assertStringContainsString('emitWarningForJitType', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
    }
}
