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

    public function testRewriterUnwrapsParenthesizedUnionParamType(): void
    {
        $source = '<?php function f((A|B) $x): void {}';
        $rewritten = DnfParenTypeRewriter::rewrite($source);
        $this->assertSame('<?php function f(A|B $x): void {}', $rewritten);
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
