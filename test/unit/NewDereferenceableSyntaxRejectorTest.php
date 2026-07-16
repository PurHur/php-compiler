<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\NewDereferenceableDesugar;
use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/** Dereferencable `new` reference profile rejector (#19684). */
final class NewDereferenceableSyntaxRejectorTest extends TestCase
{
    public function testRejectsDereferencableNewOnReferenceProfile(): void
    {
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
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new enabled on PHP 8.4.0+ target');
        }

        $code = '<?php echo (new A())->x();';
        self::assertSame($code, NewDereferenceableSyntaxRejector::reject($code, 'test.php'));
    }

    public function testDesugarNoOpWhenDisabled(): void
    {
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            self::markTestSkipped('dereferencable new enabled on PHP 8.4.0+ target');
        }

        $src = '<?php echo new Greeter()->greet();';
        self::assertSame($src, NewDereferenceableDesugar::desugar($src));
    }
}
