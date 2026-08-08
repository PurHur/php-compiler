<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CurlyBraceOffsetRejector;

/** @covers issue #5313 / #29098 — curly-brace offset syntax rejected at compile time */
final class CurlyBraceOffsetRejectorTest extends TestCase
{
    private ?string $savedProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->savedProfile = false === $raw ? null : $raw;
    }

    protected function tearDown(): void
    {
        if (null === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->savedProfile;
            $_SERVER['PHP_COMPILER_PROFILE'] = $this->savedProfile;
        }
    }

    public function testVariableCurlyOffsetIsRejectedAsLegacyFatalOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(
            'Array and string offset access syntax with curly braces is no longer supported'
        );

        CurlyBraceOffsetRejector::reject('<?php $s{1};', 'test.php');
    }

    /** @covers issue #29098 — Zend 8.4+ ParseError wording after $var */
    public function testVariableCurlyOffsetIsRejectedAsParseErrorOnProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

        try {
            CurlyBraceOffsetRejector::reject('<?php $s{1};', 'test.php');
            self::fail('expected CompileFatal');
        } catch (CompileFatal $e) {
            self::assertSame(
                'syntax error, unexpected token "{", expecting "," or ";"',
                $e->getMessage()
            );
            self::assertTrue(CompileFatal::isSyntaxParseErrorMessage($e->getMessage()));
        }
    }

    public function testStringLiteralCurlyOffsetIsRejected(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(
            'Array and string offset access syntax with curly braces is no longer supported'
        );

        CurlyBraceOffsetRejector::reject('<?php "abc"{1};', 'test.php');
    }

    /** @covers issue #29098 — Zend 8.4 string-literal lhs has no "expecting" clause */
    public function testStringLiteralCurlyOffsetIsRejectedAsParseErrorOnProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

        try {
            CurlyBraceOffsetRejector::reject('<?php "abc"{1};', 'test.php');
            self::fail('expected CompileFatal');
        } catch (CompileFatal $e) {
            self::assertSame('syntax error, unexpected token "{"', $e->getMessage());
            self::assertTrue(CompileFatal::isSyntaxParseErrorMessage($e->getMessage()));
        }
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

    public function testAnonymousClassCtorArgsBraceWithSpaceIsAllowed(): void
    {
        $code = '<?php $o = new class (1) { public function __construct(private int $x) {} };';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    public function testReadonlyAnonymousClassCtorArgsBraceIsAllowed(): void
    {
        $code = '<?php $o = new readonly class(5) { public function __construct(public int $x) {} };';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }

    /** @covers issue #21885 — whitespace between class and ctor arg list */
    public function testReadonlyAnonymousClassCtorArgsBraceWithSpaceIsAllowed(): void
    {
        $code = '<?php $o = new readonly class (5) { public function __construct(public int $x) {} };';
        self::assertSame($code, CurlyBraceOffsetRejector::reject($code, 'test.php'));
    }
}
