<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Function-local static array mutations must persist and remain readable as call args (#28038).
 *
 * Regression: BoundVariable call args were misclassified as embedded literals via
 * unwrapCfgLiteralOperand(name → string), so ARG_SEND used an empty fresh slot.
 */
final class FunctionStaticArrayDimWriteTest extends TestCase
{
    public function testAppendPersistsAcrossCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = [1, 2, 3];
  $x[] = 4;
  return implode(",", $x);
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("1,2,3,4\n1,2,3,4,4\n", $this->runVm($code));
    }

    public function testUnsetPersistsAcrossCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = ["a"=>1,"b"=>2];
  unset($x["a"]);
  return json_encode($x);
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("{\"b\":2}\n{\"b\":2}\n", $this->runVm($code));
    }

    public function testArrayShiftPersistsAcrossCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = [1, 2, 3];
  $v = array_shift($x);
  return $v.":".implode(",", $x);
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("1:2,3\n2:3\n", $this->runVm($code));
    }

    public function testReadonlyStaticAsCallArg(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = [1, 2, 3];
  return implode(",", $x);
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("1,2,3\n1,2,3\n", $this->runVm($code));
    }

    public function testAppendArgSendUsesDeclareSlot(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = [1, 2, 3];
  $x[] = 4;
  return count($x);
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'function_static_array_dim_write_slots.php');
        $fnBlock = $this->findFunctionBlock($block, 'f');
        $this->assertNotNull($fnBlock);
        $declareSlot = null;
        $lastSend = null;
        foreach ($fnBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_FUNCTION_STATIC === $op->type) {
                $declareSlot = (int) $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $lastSend = (int) $op->arg1;
            }
        }
        $this->assertNotNull($declareSlot);
        $this->assertNotNull($lastSend);
        $this->assertSame(
            $declareSlot,
            $lastSend,
            'count($static) ARG_SEND must reuse DECLARE_FUNCTION_STATIC CV slot'
        );
    }

    private function findFunctionBlock(Block $root, string $name): ?Block
    {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof Block || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            if (null !== $block->func && $name === ($block->func->name ?? null)) {
                return $block;
            }
            foreach ($block->opCodes as $op) {
                if (null !== $op->block1) {
                    $stack[] = $op->block1;
                }
                if (null !== $op->block2) {
                    $stack[] = $op->block2;
                }
            }
            foreach ($block->blocks ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return null;
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'function_static_array_dim_write.php');
        ob_start();
        $rt->run($block);

        return (string) ob_get_clean();
    }
}
