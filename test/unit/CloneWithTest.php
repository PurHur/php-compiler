<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\CloneWithDesugar;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PHP 8.3+ clone with { } desugar (#4513). */
final class CloneWithTest extends TestCase
{
    public function testDesugarRewritesCloneWith(): void
    {
        $input = '<?php $d = clone $c with { x: 2, y: "b" };';
        $expected = '<?php $d = (function ($__phpc_o) { $__phpc_r = clone $__phpc_o;__phpc_clone_with_reinit($__phpc_r, [\'x\', \'y\']);$__phpc_r->x = 2;$__phpc_r->y = "b";__phpc_clone_with_reinit_done($__phpc_r);return $__phpc_r; })($c);';
        $this->assertSame($expected, CloneWithDesugar::desugar($input));
    }

    public function testVmCloneWithMatchesZend(): void
    {
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

    public function testVmCloneWithReadonlyProperty(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone $c with { x: 2 };
var_export($d->x);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('2', ob_get_clean());
    }
}
