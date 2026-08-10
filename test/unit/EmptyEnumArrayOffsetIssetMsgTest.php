<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPCompiler\VM\EnumCaseSupport;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29568 — empty($arr[E::A]) TypeError suffix matches isset()/Zend.
 *
 * php-src: Zend/zend_execute.c — ZEND_ISSET_ISEMPTY_DIM_OBJ;
 * Zend/zend.c — zend_illegal_container_offset.
 */
final class EmptyEnumArrayOffsetIssetMsgTest extends TestCase
{
    private const EXPECTED =
        "TypeError:Illegal offset type in isset or empty\n"
        ."TypeError:Illegal offset type in isset or empty\n"
        ."TypeError:Illegal offset type\n";

    private const EXPECTED_84 =
        "TypeError:Cannot access offset of type E in isset or empty\n"
        ."TypeError:Cannot access offset of type E in isset or empty\n"
        ."TypeError:Cannot access offset of type E on array\n";

    public function testEmptyEnumOffsetMessageMatchesIssetViaRuntime(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/empty_enum_array_offset_isset_msg_29568.php'
        );
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            $code,
            'empty_enum_array_offset_isset_msg_29568.php'
        );
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(self::EXPECTED, $output);
    }

    public function testEmptyEnumOffsetMessageMatchesIssetViaVmBin(): void
    {
        $this->assertBinOutput('bin/vm.php', self::EXPECTED);
    }

    public function testEmptyEnumOffsetMessageMatchesIssetViaJitBin(): void
    {
        $this->assertBinOutput('bin/jit.php', self::EXPECTED);
    }

    public function testProfile84TypedMessageViaVmBin(): void
    {
        $this->assertBinOutput('bin/vm.php', self::EXPECTED_84, '8.4');
    }

    public function testProfile84TypedMessageViaJitBin(): void
    {
        $this->assertBinOutput('bin/jit.php', self::EXPECTED_84, '8.4');
    }

    /** @covers \PHPCompiler\VM\EnumCaseSupport::formatIllegalContainerOffsetMessage */
    public function testFormatIllegalContainerOffsetMessageIssetEmptyProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedIllegalContainerOffset());
            $this->assertSame(
                'Cannot access offset of type E in isset or empty',
                EnumCaseSupport::formatIllegalContainerOffsetMessage(
                    'E',
                    'Illegal offset type in isset or empty'
                )
            );
            $this->assertSame(
                'Cannot access offset of type E on array',
                EnumCaseSupport::formatIllegalContainerOffsetMessage('E', 'Illegal offset type')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    private function assertBinOutput(string $binRel, string $expected, ?string $profile = null): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/empty_enum_array_offset_isset_msg_29568.php';
        $env = '';
        if (null !== $profile) {
            $env = 'PHP_COMPILER_PROFILE='.escapeshellarg($profile).' ';
        }
        $cmd = $env.escapeshellarg(PHP_BINARY).' -d zend.exception_ignore_args=0 '
            .escapeshellarg($root.'/'.$binRel).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $joined = implode("\n", $out)."\n";
        $this->assertStringContainsString($expected, $joined);
    }
}
