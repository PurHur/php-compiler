<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Guard: int|never signatures are compile-fatal per Zend (#14334).
 *
 * @covers issue #14334
 */
final class NeverUnionCompileTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testMaintainerNeverUnionGapReproRejectsAtCompileTime(): void
    {
        $repro = self::$root.'/test/repro/maintainer_gap_never_union_type.php';
        self::assertFileIsReadable($repro);

        exec('php '.escapeshellarg($repro).' 2>&1', $lines, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testIntNeverReturnTypeRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): int|never {
    throw new Exception('x');
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'int_never_return.php');
    }

    public function testIntNeverParamTypeRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(int|never $x): int {
    return $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'int_never_param.php');
    }
}
