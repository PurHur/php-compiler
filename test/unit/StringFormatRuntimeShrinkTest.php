<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SprintfJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmNumberFormat;
use PHPCompiler\ext\standard\VmSprintf;
use PHPUnit\Framework\TestCase;

/** sprintf/printf/number_format JIT routes through SprintfJitHelper PHP not StringFormatJit LLVM (#9131, #13146). */
final class StringFormatRuntimeShrinkTest extends TestCase
{
    public function testStringFormatUsesSprintfJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFormat.php');
        $this->assertStringContainsString('SprintfJitHelper', $source);
        $this->assertStringContainsString('PackArgvSerialize::ensureLinked', $source);
        $this->assertStringNotContainsString('StringFormatJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('emitCompilerSprintf', $source);
        $this->assertStringNotContainsString('__phpc_fmt_append_spec_snprintf', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFormatJit.php');
    }

    public function testSprintfJitHelperMatchesVmSprintf(): void
    {
        $blob = \chr(1).\pack('q', 7);
        $this->assertSame(
            VmSprintf::format('%03d', [(function (): \PHPCompiler\VM\Variable {
                $v = new \PHPCompiler\VM\Variable();
                $v->int(7);

                return $v;
            })()]),
            SprintfJitHelper::sprintfArgv('%03d', $blob)
        );
    }

    public function testSprintfJitHelperNumberFormatMatchesVmNumberFormat(): void
    {
        $this->assertSame(
            VmNumberFormat::format(1234.5, 2, '.', ','),
            SprintfJitHelper::numberFormat(1234.5, 2, '.', ',')
        );
        $this->assertSame('nan', SprintfJitHelper::numberFormat(NAN, 0, '.', ','));
        $this->assertSame(
            '3',
            SprintfJitHelper::numberFormat(2.5, 0, '.', '', StdlibConstants::PHP_ROUND_HALF_UP)
        );
        $this->assertSame(
            '2',
            SprintfJitHelper::numberFormat(
                2.5,
                0,
                '.',
                '',
                StdlibConstants::PHP_ROUND_TOWARD_ZERO
            )
        );
        $this->assertSame(
            '1.10000000000000008882',
            SprintfJitHelper::numberFormat(1.1, 20, '.', ',')
        );
        $this->assertSame(
            VmNumberFormat::format(1.1, 20),
            SprintfJitHelper::numberFormat(1.1, 20, '.', ',')
        );
    }
}
