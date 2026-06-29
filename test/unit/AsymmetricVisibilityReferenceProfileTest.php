<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPUnit\Framework\TestCase;

/** Asymmetric visibility reference profile gate (#12508). */
final class AsymmetricVisibilityReferenceProfileTest extends TestCase
{
    public function testSupportsAsymmetricVisibilityTrueOn84DevForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testRewriterNoOpWhenAsymmetricDisabled(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $src = '<?php class C { private(set) string $x = "a"; }';
        $this->assertSame($src, AsymmetricVisibilityRewriter::rewrite($src));
    }

    public function testRejectorThrowsOnPromotedPrivateSet(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRejector::PARSE_MESSAGE);
        AsymmetricVisibilityRejector::reject(
            '<?php class C { public function __construct(private(set) int $x) {} }',
            'promoted.php'
        );
    }

    public function testRuntimeRejectsPromotedPrivateSet(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                '<?php class C { public function __construct(private(set) int $x) {} }',
                'promoted.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(AsymmetricVisibilityRejector::PARSE_MESSAGE, $e->getMessage());
        }
    }

    public function testRejectorThrowsMultipleModifiersOnPublicPrivateSet(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRejector::reject(
            file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_double_modifier.php'),
            'asymmetric_double_modifier.php'
        );
    }

    public function testRuntimeRejectsPublicPrivateSetWithZendMessage(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_double_modifier.php'),
                'asymmetric_double_modifier.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE, $e->getMessage());
            $this->assertSame(5, $e->sourceLine);
        }
    }

    public function testRuntimeRejectsPublicPrivateSetCompileRepro(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_public_private_set_compile.php'),
                'maintainer_gap_asymmetric_public_private_set_compile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE, $e->getMessage());
        }
    }
}
