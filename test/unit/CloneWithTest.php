<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\CloneWithDesugar;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PHP 8.3+ clone with { } desugar (#4513). */
final class CloneWithTest extends TestCase
{
    private function skipUnlessCloneWithEnabled(): void
    {
        if (!CompilerVersion::supportsCloneWithSyntax()) {
            $this->markTestSkipped('clone-with syntax disabled on reference profile (#12987)');
        }
    }

    public function testDesugarRewritesCloneWith(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone $c with { x: 2, y: "b" };';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'x\', \'y\');$__phpc_r->x = 2;$__phpc_r->y = "b";phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    /** Issue #9743 — PHP 8.4+ clone($obj, ['prop']) functional syntax. */
    public function testDesugarRewritesCloneCallWithPropertyList(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone($c, [\'a\']);';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'a\');phpc_clone_with_reinit($__phpc_r, \'a\');phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testDesugarRewritesCloneCallWithAssociativeArray(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone($c, [\'x\' => 2]);';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'x\');$__phpc_r->x = 2;phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    /** Issue #12939 — PHP 8.4+ `clone ($obj, with: ['prop' => $val])` named argument form. */
    public function testDesugarRewritesCloneCallWithNamedWithArg(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone ($c, with: [\'x\' => 2]);';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'x\');$__phpc_r->x = 2;phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testDesugarRewritesCloneCallWithNamedWithReinitList(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone ($c, with: [\'a\']);';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'a\');phpc_clone_with_reinit($__phpc_r, \'a\');phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testVmCloneCallWithNamedWithArg(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class Point {
    public int $x = 1;
    public int $y = 2;
}
$p = new Point();
$q = clone ($p, with: ['x' => 9]);
echo $q->x, ',', $q->y, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("9,2\n", ob_get_clean());
    }

    /** Issue #9995 — PHP 8.4+ `clone $obj with ['prop']` keyword array syntax. */
    public function testDesugarRewritesCloneWithKeywordArray(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone $c with [\'a\'];';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'a\');phpc_clone_with_reinit($__phpc_r, \'a\');phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testDesugarRewritesCloneWithKeywordAssociativeArray(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = clone $c with [\'x\' => 2];';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'x\');$__phpc_r->x = 2;phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    /** Issue #10496 — PHP 8.4+ `(clone $obj) with [...]` parenthesized operand. */
    public function testDesugarRewritesParenCloneWithKeywordArray(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = (clone $c) with [\'a\'];';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'a\');phpc_clone_with_reinit($__phpc_r, \'a\');phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testDesugarRewritesParenCloneWithAssociativeArray(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = (clone $c) with [\'x\' => 2];';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'x\');$__phpc_r->x = 2;phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testDesugarRewritesParenCloneWithBlock(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $input = '<?php $d = (clone $c) with { x: 2 };';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;phpc_clone_with_begin($__phpc_r, \'x\');$__phpc_r->x = 2;phpc_clone_with_end($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testVmParenCloneWithKeywordArrayValueOverrides(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
    public readonly int $y;
    public function __construct() { $this->y = 9; }
}
$c = new C();
$d = (clone $c) with ['x' => 2, 'y' => 3];
echo $d->x, ',', $d->y, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2,3\n", ob_get_clean());
    }

    public function testVmCloneWithKeywordArrayReadonlyReinit(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class W {
    public int $a = 1;
    public readonly int $b;
    public function __construct() { $this->b = 2; }
}
$w = new W();
$w2 = clone $w with ['a'];
echo $w2->a, ',', $w2->b, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1,2\n", ob_get_clean());
    }

    public function testVmCloneWithKeywordArrayValueOverrides(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
    public readonly int $y;
    public function __construct() { $this->y = 9; }
}
$c = new C();
$d = clone $c with ['x' => 2, 'y' => 3];
echo $d->x, ',', $d->y, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2,3\n", ob_get_clean());
    }

    public function testVmCloneCallSyntaxReadonlyReinit(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class W {
    public int $a = 1;
    public readonly int $b;
    public function __construct() { $this->b = 2; }
}
$w = new W();
$w2 = clone($w, ['a']);
echo $w2->a, ',', $w2->b, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1,2\n", ob_get_clean());
    }

    public function testVmCloneCallSyntaxWithValueOverrides(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
    public readonly int $y;
    public function __construct() { $this->y = 9; }
}
$c = new C();
$d = clone($c, ['x' => 2, 'y' => 3]);
echo $d->x, ',', $d->y, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2,3\n", ob_get_clean());
    }

    public function testVmCloneWithReadonlyReinit(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone $c with { x: 2 };
echo $d->x, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testJitCloneWithReadonlyReinit(): void
    {
        $this->skipUnlessCloneWithEnabled();
        if (!\getenv('PHP_COMPILER_LLVM_PATH') && !\is_dir(__DIR__.'/../../.llvm')) {
            $this->markTestSkipped('LLVM not available');
        }
        $code = <<<'PHP'
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone $c with { x: 2 };
echo $d->x, "\n";
PHP;
        $jitBin = __DIR__.'/../../bin/jit.php';
        $proc = \proc_open(
            ['php', $jitBin, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__.'/../..'
        );
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("2\n", $stdout);
    }

    public function testVmCloneWithMatchesZend(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
    public string $y = 'a';
}
$c = new C();
$d = clone $c with { x: 2, y: 'b' };
var_export([$d->x, $d->y]);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("array (\n  0 => 2,\n  1 => 'b',\n)", ob_get_clean());
    }

    /** Issue #10310 — property list without value reinitializes defaults, not source values. */
    public function testVmCloneWithPropertyListReinitializesDefault(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
declare(strict_types=1);

class W {
    public int $a = 1;
    public readonly int $b;

    public function __construct() {
        $this->b = 2;
    }
}

$w = new W();
$w->a = 99;
$w2 = clone($w, ['a']);
var_export([$w->a, $w->b, $w2->a, $w2->b]);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("array (\n  0 => 99,\n  1 => 2,\n  2 => 1,\n  3 => 2,\n)", ob_get_clean());
    }

    /** Issue #10165 — clone-with overrides apply after __clone(); closure IIFE must receive object. */
    public function testVmCloneWithOverrideAfterCloneMagic(): void
    {
        $this->skipUnlessCloneWithEnabled();
        $code = <<<'PHP'
<?php
declare(strict_types=1);

class C {
    public int $x = 1;

    public function __clone(): void {
        $this->x = 99;
    }
}

$c = new C();
$d = clone $c with ['x' => 2];
var_export([$c->x, $d->x]);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("array (\n  0 => 1,\n  1 => 2,\n)", ob_get_clean());
    }
}
