<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\PipeOperatorDesugar;
use PHPUnit\Framework\TestCase;

/** PHP 8.4+ pipe operator (|>) VM desugar (#3243). */
final class PipeOperatorTest extends TestCase
{
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
            '<?php $fn = (fn(...$__pipe_a) => strtoupper(...)("hi", ...$__pipe_a));',
            PipeOperatorDesugar::desugar('<?php $fn = "hi" |> strtoupper(...);')
        );
    }

    public function testVmPipeFirstClassCallableAssignmentIsClosure(): void
    {
        $code = <<<'PHP'
<?php
$fn = "hi" |> strtoupper(...);
echo $fn instanceof Closure ? "closure\n" : get_debug_type($fn), "\n";
echo $fn(), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("closure\n\nHI\n", ob_get_clean());
    }
}
