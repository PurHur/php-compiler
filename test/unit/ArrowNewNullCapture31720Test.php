<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Arrow auto-capture beside literal null in `new` args (#31720).
 *
 * php-src: Zend/zend_compile.c (ZEND_AST_ARROW_FUNC + ZEND_AST_NEW).
 */
final class ArrowNewNullCapture31720Test extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testVmArrowNewKeepsCaptureBesideNullLiteral(): void
    {
        $repro = self::$root.'/test/repro/maintainer_gap_arrow_new_null_arg.php';
        $this->assertFileExists($repro);

        $cmd = 'php '.escapeshellarg(self::$root.'/bin/vm.php').' '.escapeshellarg($repro).' 2>/dev/null';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        $out = implode("\n", $lines);

        $this->assertStringContainsString("mixed_null a='captured' b=NULL", $out);
        $this->assertStringContainsString("null_then_cap a=NULL b='captured'", $out);
        $this->assertStringContainsString("int_control a='captured' b=0", $out);
        $this->assertStringContainsString("dup_control a='captured' b='captured'", $out);
        $this->assertStringContainsString("long_use a='captured' b=NULL", $out);
    }

    public function testTryFoldSkipsNamedLocalsBesideNullConstFetch(): void
    {
        $src = (string) file_get_contents(self::$root.'/lib/Compiler.php');
        $this->assertStringContainsString('callHasTrailingHoistedBoolNullConstFetch', $src);
        $this->assertStringContainsString('#31720', $src);
        $this->assertStringContainsString('callArgIsDeadInlineTemporary($candidate)', $src);
        $this->assertMatchesRegularExpression(
            '/nonEmbeddedArgIndices[\s\S]*callArgIsDeadInlineTemporary\(\$candidate\)[\s\S]*boolNullArgIndices/s',
            $src,
            'positional null/true/false ConstFetch folding must skip named CVs (#31720)'
        );
    }
}
