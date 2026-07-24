<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\PipeOperatorDesugar;
use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/** Pipe operator reference profile rejector (#12424, #18007). */
final class PipeOperatorSyntaxRejectorTest extends TestCase
{
    public function testRejectsPipeOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsPipeOperator()) {
            self::markTestSkipped('pipe operator enabled on PHP 8.5.0+ target');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PipeOperatorDesugar::REFERENCE_PROFILE_UNEXPECTED_GT);

        PipeOperatorSyntaxRejector::reject('<?php $x = 5 |> fn ($v) => $v * 2;', 'test.php');
    }

    public function testReferenceProfileSyntaxErrorLine(): void
    {
        if (CompilerVersion::supportsPipeOperator()) {
            self::markTestSkipped('pipe operator enabled on PHP 8.5.0+ target');
        }

        $error = PipeOperatorDesugar::referenceProfileSyntaxError(<<<'PHP'
<?php
$a = 1;
$b = 2 |> strval;
PHP
        );
        self::assertNotNull($error);
        self::assertSame(3, $error['line']);
        self::assertSame(PipeOperatorDesugar::REFERENCE_PROFILE_UNEXPECTED_GT, $error['message']);
    }

    public function testNoOpWithoutPipeToken(): void
    {
        if (CompilerVersion::supportsPipeOperator()) {
            self::markTestSkipped('pipe operator enabled on PHP 8.5.0+ target');
        }

        $code = '<?php echo 1 + 2;';
        self::assertSame($code, PipeOperatorSyntaxRejector::reject($code, 'test.php'));
    }

    public function testAllowsPipeOnForwardProfile(): void
    {
        if (!CompilerVersion::supportsPipeOperator()) {
            self::markTestSkipped('reference profile only');
        }

        $code = '<?php $x = 5 |> fn ($v) => $v * 2;';
        self::assertSame($code, PipeOperatorSyntaxRejector::reject($code, 'test.php'));
    }
}
