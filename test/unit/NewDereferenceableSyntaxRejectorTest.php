<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\NewDereferenceableDesugar;
use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/** Dereferencable `new` rejector (#19684, #20598). */
final class NewDereferenceableSyntaxRejectorTest extends TestCase
{
    private ?string $previousProfile = null;

    protected function setUp(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->previousProfile = false === $prev ? null : $prev;
    }

    protected function tearDown(): void
    {
        if (null === $this->previousProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->previousProfile);
        }
    }

    public function testRejectsBareNamedObjectDerefOnForwardProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new forward profile unavailable');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NewDereferenceableDesugar::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR);

        NewDereferenceableSyntaxRejector::reject(
            '<?php class Greeter { public function hello(): string { return "hi"; } } echo new Greeter->hello();',
            'bare_new.php'
        );
    }

    public function testAllowsCtorParensObjectDerefOnForwardProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new forward profile unavailable');
        }

        $code = '<?php echo new Greeter()->hello();';
        self::assertSame($code, NewDereferenceableSyntaxRejector::reject($code, 'ok_new.php'));
    }

    public function testRejectsDereferencableNewOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new enabled on PHP 8.4.0+ target');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NewDereferenceableDesugar::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR);

        NewDereferenceableSyntaxRejector::reject(
            '<?php class A { public function x(){ return 1; } } echo new A()->x();',
            'test.php'
        );
    }

    public function testReferenceProfileSyntaxErrorLineAndMessage(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new enabled on PHP 8.4.0+ target');
        }

        $error = NewDereferenceableDesugar::referenceProfileSyntaxError(<<<'PHP'
<?php
class A { public function x(){ return 1; } }
echo new A()->x();
PHP
        );
        self::assertNotNull($error);
        self::assertSame(3, $error['line']);
        self::assertSame(NewDereferenceableDesugar::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR, $error['message']);
    }

    public function testNoOpForParenthesizedNew(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new enabled on PHP 8.4.0+ target');
        }

        $code = '<?php echo (new A())->x();';
        self::assertSame($code, NewDereferenceableSyntaxRejector::reject($code, 'test.php'));
    }

    public function testDesugarNoOpWhenDisabled(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new enabled on PHP 8.4.0+ target');
        }

        $src = '<?php echo new Greeter()->greet();';
        self::assertSame($src, NewDereferenceableDesugar::desugar($src));
    }

    public function testRejectsCtorParensObjectDerefOnDefault84DevReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new unexpectedly enabled on default 8.2 advertisement');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NewDereferenceableDesugar::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR);

        NewDereferenceableSyntaxRejector::reject(
            '<?php class C { function m() { return 2; } } echo new C()->m();',
            'default_new.php'
        );
    }

    /** Issue #31164: default advertisement (phpversion 8.2.31) parse-errors like Zend 8.2. */
    public function testVmParseAndCompileRejectsOnDefaultProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new unexpectedly enabled on default 8.2 advertisement');
        }

        $code = <<<'PHP'
<?php
class C { function m() { return 2; } }
echo new C()->m(), "\n";
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NewDereferenceableDesugar::REFERENCE_PROFILE_UNEXPECTED_OBJECT_OPERATOR);
        (new Runtime())->parseAndCompile($code, 'issue_31164.php');
    }
}
