<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

final class BareThrowSyntaxRejectorTest extends TestCase
{
    public function testRejectsBareThrowOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsBareRethrow()) {
            self::markTestSkipped('bare rethrow enabled on PHP 8.4.0+ target');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token ";"');

        BareThrowSyntaxRejector::reject(<<<'PHP'
<?php
try {
    throw;
} catch (E $e) {
}
PHP
, 'test.php');
    }

    public function testRejectsBareThrowInsideNonCapturingCatchOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsBareRethrow()) {
            self::markTestSkipped('bare rethrow enabled on PHP 8.4.0+ target');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token ";"');

        BareThrowSyntaxRejector::reject(<<<'PHP'
<?php
try {
    throw new Inner();
} catch (Inner) {
    throw;
}
PHP
, 'test.php');
    }

    public function testAllowsThrowWithExpressionOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsBareRethrow()) {
            self::markTestSkipped('bare rethrow enabled on PHP 8.4.0+ target');
        }

        $code = '<?php throw new E();';
        self::assertSame($code, BareThrowSyntaxRejector::reject($code, 'test.php'));
    }

    public function testAllowsBareThrowOnForwardProfile(): void
    {
        if (!CompilerVersion::supportsBareRethrow()) {
            self::markTestSkipped('reference profile only');
        }

        $code = <<<'PHP'
<?php
try {
    throw;
} catch (E $e) {
}
PHP;
        self::assertSame($code, BareThrowSyntaxRejector::reject($code, 'test.php'));
    }
}
