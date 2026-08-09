<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * `$r = ($obj->undeclared = $v)` must invoke __set once (zend_std_write_property; #29194).
 */
final class MagicSetAssignExprOnceTest extends TestCase
{
    public function testAssignExprInvokesMagicSetOnceOnVm(): void
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/test/repro/maintainer_probe_magic_set_expr.php';
        $this->assertSame("set\ncalls=1 r=5\n", $this->capture($repo . '/bin/vm.php', $path));
    }

    public function testPropertyAssignExprEmitsSingleWriteToFetchSlot(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile(
            <<<'PHP'
<?php
class A {
    public function __set($k, $v) {}
}
$a = new A();
$r = ($a->x = 5);
PHP,
            'magic_set_assign_expr_once.php'
        );

        $assignsToFetch = 0;
        $fetchSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_PROPERTY_FETCH === $op->type) {
                $fetchSlot = $op->arg1;
            }
            if (OpCode::TYPE_ASSIGN === $op->type && null !== $fetchSlot && $op->arg2 === $fetchSlot) {
                ++$assignsToFetch;
            }
        }
        $this->assertNotNull($fetchSlot);
        $this->assertSame(1, $assignsToFetch, 'property lvalue must be written exactly once');
    }

    private function capture(string $bin, string $script): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($script) . ' 2>/dev/null';
        $out = shell_exec($cmd);

        return is_string($out) ? $out : '';
    }
}
