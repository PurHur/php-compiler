<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPUnit\Framework\TestCase;

/** Asymmetric visibility reference profile gate (#12508). */
final class AsymmetricVisibilityReferenceProfileTest extends TestCase
{
    public function testSupportsAsymmetricVisibilityFalseOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ forward profile (#14554)');
        }
        $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testRejectorThrowsOnPropertyPrivateSet(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRejector::PARSE_MESSAGE);
        AsymmetricVisibilityRejector::reject(
            file_get_contents(__DIR__.'/../repro/maintainer_gap_private_set_reference_profile.php'),
            'maintainer_gap_private_set_reference_profile.php'
        );
    }

    public function testRuntimeRejectsPropertyPrivateSet(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_private_set_reference_profile.php'),
                'maintainer_gap_private_set_reference_profile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(AsymmetricVisibilityRejector::PARSE_MESSAGE, $e->getMessage());
        }
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

    public function testRuntimeRejectsPublicPrivateSetWithProfileGate(): void
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

    public function testRuntimeRejectsPublicPrivateSetProfileGateRepro(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_visibility_profile_gate.php'),
                'maintainer_gap_asymmetric_visibility_profile_gate.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE, $e->getMessage());
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

    /** @covers issue #16452 — asymmetric scope + hook block on reference profile */
    public function testRuntimeRejectsAsymmetricGetOnlyHookWithZendPrivateTokenMessage(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_get_only_hook_compile.php'),
                'maintainer_gap_asymmetric_get_only_hook_compile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString('syntax error, unexpected token "private"', $e->getMessage());
            $this->assertSame(5, $e->sourceLine);
        }
    }

    public function testRejectorThrowsAsymmetricGetOnlyHookOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token "private"');
        AsymmetricVisibilityRejector::reject(
            file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_get_only_hook_compile.php'),
            'maintainer_gap_asymmetric_get_only_hook_compile.php'
        );
    }

    /** @covers issue #16450 — parenthesized asymmetric set on reference profile */
    public function testRejectorThrowsParenthesizedAsymmetricSetOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token "private"');
        AsymmetricVisibilityRejector::reject(
            file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_paren_reference_profile.php'),
            'maintainer_gap_asymmetric_paren_reference_profile.php'
        );
    }

    public function testRuntimeRejectsParenthesizedAsymmetricSetOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_paren_reference_profile.php'),
                'maintainer_gap_asymmetric_paren_reference_profile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString('syntax error, unexpected token "private"', $e->getMessage());
            $this->assertSame(7, $e->sourceLine);
        }
    }

    /** @covers issue #18062 — bare modifier before parenthesized form in same file */
    public function testRejectorThrowsBareModifierBeforeParenthesizedForm(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRejector::reject(
            file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_visibility_parse_line.php'),
            'maintainer_gap_asymmetric_visibility_parse_line.php'
        );
    }

    public function testRuntimeRejectsBareModifierBeforeParenthesizedForm(): void
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_asymmetric_visibility_parse_line.php'),
                'maintainer_gap_asymmetric_visibility_parse_line.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE, $e->getMessage());
            $this->assertSame(6, $e->sourceLine);
        }
    }
}
