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

    public function testRejectorAllowsParenthesizedBitwiseAndOfBareNames(): void
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            $this->markTestSkipped('parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
        }
        $source = <<<'PHP'
<?php
echo (E_ERROR & E_WARNING), "\n";
echo (E_ALL & E_WARNING) !== 0 ? "y\n" : "n\n";
var_export(E_ERROR & E_WARNING);
PHP;
        $this->assertNull(DnfParenTypeRewriter::referenceProfileSyntaxError($source));
        $this->assertSame($source, DnfParenIntersectionSyntaxRejector::reject($source, 'bitand.php'));
    }

    public function testRejectorAllowsTernaryElseParenthesizedBitwiseAnd(): void
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            $this->markTestSkipped('parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
        }
        $source = '<?php $x ? ($a) : (E_ERROR & E_WARNING);';
        $this->assertNull(DnfParenTypeRewriter::referenceProfileSyntaxError($source));
    }

    public function testRuntimeEvaluatesParenthesizedBitwiseAndBareNames(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (E_ERROR & E_WARNING), "\n";
echo (E_ALL & E_WARNING) !== 0 ? "y\n" : "n\n";
var_export(E_ERROR & E_WARNING);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'paren_bitand_bare_names.php'));
        $this->assertSame("0\ny\n0\n", ob_get_clean());
    }
}
