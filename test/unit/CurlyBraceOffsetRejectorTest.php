<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CurlyBraceOffsetRejector;

/** @covers issue #5313 — curly-brace offset syntax rejected at compile time */
final class CurlyBraceOffsetRejectorTest extends TestCase
{
    public function testVariableCurlyOffsetIsRejected(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(
            'Array and string offset access syntax with curly braces is no longer supported'
        );

        CurlyBraceOffsetRejector::reject('<?php $s{1};', 'test.php');
    }

    public function testStringLiteralCurlyOffsetIsRejected(): void
    {
        $this->expectException(CompileFatal::class);

        CurlyBraceOffsetRejector::reject('<?php "abc"{1};', 'test.php');
    }

    public function testSquareBracketOffsetIsAllowed(): void
    {
        $code = '<?php echo "abc"[0];';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    public function testMatchBlockIsAllowed(): void
    {
        $code = '<?php echo match (1) { 1 => "a", default => "b" };';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    public function testDynamicPropertyBraceIsAllowed(): void
    {
        $code = '<?php $o->{prop};';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    public function testFunctionBodyBraceIsAllowed(): void
    {
        $code = '<?php function f() { return 1; }';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    public function testAnonymousClassCtorArgsBraceIsAllowed(): void
    {
        $code = '<?php $o = new class(1) { public function __construct(private int $x) {} };';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    public function testReadonlyAnonymousClassCtorArgsBraceIsAllowed(): void
    {
        $code = '<?php $o = new readonly class(5) { public function __construct(public int $x) {} };';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }
}
