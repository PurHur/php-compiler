<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\PipeOperatorDesugar;
use PhpParser\Error as ParserError;
use PHPUnit\Framework\TestCase;

/** PHP 8.4+ pipe operator (|>) VM desugar (#3243, #7219). */
final class PipeOperatorTest extends TestCase
{
    public function testVmPipeBareCallableName(): void
    {
        $code = <<<'PHP'
<?php
echo 1 |> strval, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testDesugarRewritesBareCallableName(): void
    {
        $this->assertSame(
            '<?php echo strval(1), "\n";',
            PipeOperatorDesugar::desugar('<?php echo 1 |> strval, "\n";')
        );
    }

    public function testUnparenthesizedArrowFunctionRhsIsParseError(): void
    {
        $this->expectException(ParserError::class);
        $this->expectExceptionMessage('Arrow functions on the right-hand side of the pipe operator must be parenthesized');
        PipeOperatorDesugar::desugar('<?php echo 1 |> fn($x) => $x;');
    }
    public function testVmPipeWithFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
echo "hi" |> strtoupper(...);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('HI', ob_get_clean());
    }

    public function testVmPipeChain(): void
    {
        $code = <<<'PHP'
<?php
echo "hi" |> strtoupper(...) |> strlen(...);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('2', ob_get_clean());
    }

    public function testVmPipeWithConcatPrecedence(): void
    {
        $code = <<<'PHP'
<?php
echo "a" . "b" |> strtoupper(...);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('AB', ob_get_clean());
    }

    public function testDesugarRewritesSinglePipe(): void
    {
        $this->assertSame(
            '<?php echo strtoupper("hi");',
            PipeOperatorDesugar::desugar('<?php echo "hi" |> strtoupper(...);')
        );
    }

    public function testDesugarBindsPipeFirstClassCallableForAssignment(): void
    {
        $this->assertSame(
            '<?php $fn = strtoupper("hi");',
            PipeOperatorDesugar::desugar('<?php $fn = "hi" |> strtoupper(...);')
        );
    }

    public function testVmPipeFirstClassCallableAssignmentInvokes(): void
    {
        $code = <<<'PHP'
<?php
$fn = "hi" |> strtoupper(...);
echo get_debug_type($fn), "\n";
echo $fn, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("string\nHI\n", ob_get_clean());
    }

    public function testVmPipeWithArrowFunction(): void
    {
        $code = <<<'PHP'
<?php
$x = 5 |> (fn($v) => $v * 2);
var_export($x);
echo "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("10\n", ob_get_clean());
    }

    public function testVmPipeChainedArrowFunctions(): void
    {
        $code = <<<'PHP'
<?php
echo 3 |> (fn($x) => $x + 1) |> (fn($x) => $x * 2), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("8\n", ob_get_clean());
    }

    public function testDesugarRewritesArrowFunctionPipe(): void
    {
        $this->assertSame(
            '<?php $x = (fn($v) => $v * 2)(5);',
            PipeOperatorDesugar::desugar('<?php $x = 5 |> (fn($v) => $v * 2);')
        );
    }

    public function testDesugarRewritesChainedArrowFunctionPipe(): void
    {
        $this->assertSame(
            '<?php echo (fn($x) => $x * 2)((fn($x) => $x + 1)(3)), "\n";',
            PipeOperatorDesugar::desugar('<?php echo 3 |> (fn($x) => $x + 1) |> (fn($x) => $x * 2), "\n";')
        );
    }

    public function testDesugarRewritesParenthesizedArrowFunctionWithEmptyInvoke(): void
    {
        $this->assertSame(
            '<?php echo (fn($x) => $x * 2)(5), PHP_EOL;',
            PipeOperatorDesugar::desugar('<?php echo 5 |> (fn($x) => $x * 2)(), PHP_EOL;')
        );
        $this->assertSame(
            '<?php echo (fn(int $x): int => $x * 2)(5), PHP_EOL;',
            PipeOperatorDesugar::desugar('<?php echo 5 |> (fn(int $x): int => $x * 2)(), PHP_EOL;')
        );
    }

    public function testVmPipeParenthesizedArrowFunctionWithEmptyInvoke(): void
    {
        $code = <<<'PHP'
<?php
echo 5 |> (fn($x) => $x * 2)(), PHP_EOL;
echo 5 |> (fn(int $x): int => $x * 2)(), PHP_EOL;
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("10\n10\n", ob_get_clean());
    }

    /** @covers issue #10110 */
    public function testVmPipePreservesBackedEnumCaseIdentity(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
var_export(E::A |> (fn($x) => $x)());
echo "\n";
var_export(E::A |> (fn($x) => $x->name)());
echo "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("\\E::A\n'A'\n", ob_get_clean());
    }
}
