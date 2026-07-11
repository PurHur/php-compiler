<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\DnfParenTypeRewriter;
use PHPUnit\Framework\TestCase;

/** Parenthesized DNF intersection-only types reference profile gate (#14904). */
final class DnfParenIntersectionReferenceProfileTest extends TestCase
{
    public function testSupportsParenthesizedDnfIntersectionTypesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsParenthesizedDnfIntersectionTypes());
    }

    public function testRejectorThrowsOnParamTypeForm(): void
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            $this->markTestSkipped('parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('unexpected variable "$o"');
        DnfParenIntersectionSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_dnf_paren_intersection_param.php'),
            'dnf_paren_intersection_param.php'
        );
    }

    public function testRejectorThrowsOnReturnTypeForm(): void
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            $this->markTestSkipped('parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('unexpected token "{"');
        DnfParenIntersectionSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_dnf_paren_intersection_return.php'),
            'dnf_paren_intersection_return.php'
        );
    }

    public function testRewriterNoOpOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            $this->markTestSkipped('parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
        }
        $source = '<?php function f((I1&I2) $o): void {}';
        $this->assertSame($source, DnfParenTypeRewriter::rewrite($source));
    }

    public function testRuntimeRejectsMaintainerGapParamRepro(): void
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            $this->markTestSkipped('parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_dnf_paren_intersection_param.php'),
                'maintainer_gap_dnf_paren_intersection_param.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString('unexpected variable "$o"', $e->getMessage());
        }
    }
}
