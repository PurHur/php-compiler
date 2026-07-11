<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ReadonlyAnonymousClassSyntax;
use PHPUnit\Framework\TestCase;

/** Bounded `new readonly class` scan — no token_get_all on JIT bundles (#17150). */
final class ReadonlyAnonymousClassSyntaxBoundedScanTest extends TestCase
{
    public function testDetectsNewReadonlyClass(): void
    {
        $error = ReadonlyAnonymousClassSyntax::referenceProfileSyntaxError(
            "<?php\n\$o = new readonly class { public int \$x = 1; };\n"
        );
        $this->assertNotNull($error);
        $this->assertSame(ReadonlyAnonymousClassSyntax::REFERENCE_PROFILE_UNEXPECTED_READONLY, $error['message']);
    }

    public function testHashCommentScriptDoesNotFalsePositive(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/compliance/cases/php/lang/comments.phpt');
        $file = explode('--FILE--', $code, 2)[1] ?? '';
        $source = explode('--EXPECT--', $file, 2)[0];
        $this->assertNull(ReadonlyAnonymousClassSyntax::referenceProfileSyntaxError($source));
    }

    public function testNewInStringLiteralIgnored(): void
    {
        $this->assertNull(ReadonlyAnonymousClassSyntax::referenceProfileSyntaxError(
            "<?php echo 'new readonly class';\n"
        ));
    }

    public function testJitHelperFilenameSkipsHeavyReferenceProfileScan(): void
    {
        $payload = str_repeat('(', 600_000);
        $this->assertTrue(
            ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($payload, 'ArrayBuiltinHelper.php')
        );
    }
}
