<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32153 */
final class ClosureUseDuplicateCompileCheckTest extends TestCase
{
    public function testDuplicateUseIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use variable $a twice');
        $runtime->parseAndCompile(<<<'PHP'
<?php
$a = 1;
$f = function () use ($a, $a) { return $a; };
echo "accepted\n";
PHP
            ,
            'closure_use_dup.php'
        );
    }

    public function testDuplicateUseMixedByRefIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use variable $a twice');
        $runtime->parseAndCompile(<<<'PHP'
<?php
$a = 1;
$f = function () use ($a, &$a) { return $a; };
echo "accepted\n";
PHP
            ,
            'closure_use_dup_byref.php'
        );
    }

    public function testDistinctUseNamesStillCompile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
$a = 1;
$b = 2;
$f = function () use ($a, $b) { return $a + $b; };
echo $f();
PHP
            ,
            'closure_use_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testCaseDifferingUseNamesCompile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
$a = 1;
$A = 2;
$f = function () use ($a, $A) { return $a + $A; };
echo $f();
PHP
            ,
            'closure_use_case.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testMessageForIncludesDollarName(): void
    {
        $this->assertSame(
            'Cannot use variable $foo twice',
            ClosureUseDuplicateCompileCheck::messageFor('foo')
        );
    }
}
