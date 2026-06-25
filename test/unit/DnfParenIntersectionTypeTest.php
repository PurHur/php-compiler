<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\DnfParenTypeRewriter;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #9733 */
final class DnfParenIntersectionTypeTest extends TestCase
{
    public function testRewriterUnwrapsParenthesizedIntersectionParamType(): void
    {
        $source = '<?php function f((I1&I2) $o): void {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame('<?php function f(I1&I2 $o): void {}', $rewritten);
    }

    public function testRewriterUnwrapsParenthesizedIntersectionReturnType(): void
    {
        $source = '<?php function f(): (I1&I2) {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame('<?php function f(): I1&I2 {}', $rewritten);
    }

    public function testRewriterKeepsParenthesizedIntersectionBeforeUnion(): void
    {
        $source = '<?php function f((I1&I2)|null $o): void {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    /** Issue #11745: union RHS intersection arms must keep parens — php-parser rejects unwrapped `A|B&C`. */
    public function testRewriterKeepsParenthesizedIntersectionAfterUnion(): void
    {
        $source = '<?php function f(A|(B&C) $o): void {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    public function testUnionRhsIntersectionParamCompileAndRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);

interface PhpcDnfA {}
interface PhpcDnfB {}
interface PhpcDnfC {}
class PhpcDnfBC implements PhpcDnfB, PhpcDnfC {}

function phpc_dnf_probe(PhpcDnfA|(PhpcDnfB&PhpcDnfC) $arg): string {
    return $arg::class;
}

var_export(phpc_dnf_probe(new PhpcDnfBC()));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dnf_union_rhs_intersection.php'));
        $this->assertSame("'PhpcDnfBC'\n", ob_get_clean());
    }

    /** Issue #9968: union-only parenthesized leaves are a Zend parse error — do not unwrap. */
    public function testRewriterDoesNotUnwrapParenthesizedUnionOnlyParamType(): void
    {
        $source = '<?php function f((A|B) $x): void {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    public function testRewriterKeepsParenthesizedUnionBeforeIntersection(): void
    {
        $source = '<?php function f((A|B)&C $x): void {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    public function testParenthesizedUnionOnlyParamTypeFailsParse(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function acceptsUnion((string|int) $x): void { echo $x, "\n"; }
PHP;
        $this->expectException(\PhpParser\Error::class);
        $runtime->parseAndCompile($code, 'dnf_paren_union_only.php');
    }

    /** Issue #9766: non-capturing union catch must keep parens for php-parser. */
    public function testRewriterKeepsParenthesizedUnionCatchType(): void
    {
        $source = '<?php try {} catch (LogicException|TypeError) {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    public function testRewriterKeepsParenthesizedUnionCatchTypeWithVariable(): void
    {
        $source = '<?php try {} catch (LogicException|TypeError $e) {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    public function testParenthesizedIntersectionParamAndReturnCompileAndRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);

interface I1 {}
interface I2 {}
class Both implements I1, I2 {}

function accepts((I1&I2) $o): string { return 'ok'; }
function returns(): (I1&I2) { return new Both(); }

var_export(accepts(new Both()));
echo "\n";
var_export(returns() instanceof Both);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dnf_paren_intersection.php'));
        $this->assertSame("'ok'\ntrue\n", ob_get_clean());
    }

    public function testRewriterDoesNotTouchCallArgumentParens(): void
    {
        $source = '<?php var_export(returns() instanceof Both);';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame($source, $rewritten);
    }

    public function testParenthesizedIntersectionParamRejectsIncompatibleValue(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I1 {}
interface I2 {}
class Both implements I1, I2 {}

function accepts((I1&I2) $o): string { return 'ok'; }

try {
    accepts([]);
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dnf_paren_intersection_type_error.php'));
        $this->assertSame(
            "Argument must be of type I1&I2, array given\n",
            ob_get_clean()
        );
    }
}
