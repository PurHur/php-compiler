<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPUnit\Framework\TestCase;
use PhpParser\Error as ParserError;

/** Asymmetric visibility reference profile gate (#12508). */
final class AsymmetricVisibilityReferenceProfileTest extends TestCase
{
    public function testSupportsAsymmetricVisibilityFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testRewriteRejectsPrivateSetOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $src = '<?php class C { private(set) int $x; }';
        $this->expectException(ParserError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::REFERENCE_PROFILE_REJECT_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($src);
    }

    public function testVmRejectsPromotedPrivateSetOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        $this->expectException(ParserError::class);
        $runtime->parse(
            '<?php class C { public function __construct(private(set) int $x) {} }',
            'test.php'
        );
    }
}
