<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class SwitchJumpIfLoweringTest extends TestCase
{
    public function testSwitchJumpIfChainBootstrapAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/bootstrap-aot/switch_jumpif_chain.php';
        $this->assertFileExists($target);
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim($stderr !== false ? $stderr : ''));
    }

    public function testSwitchLoweringEmitsNoNullOperandSlots(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $code = <<<'PHP'
<?php
function pick(int $v): string {
    switch ($v) {
        case 1:
            return 'a';
        case 2:
            return 'b';
        default:
            return 'z';
    }
}
PHP;
        $script = $runtime->parse($code, 'switch.php');
        $block = $runtime->compile($script);
        $nullOps = [];
        $this->collectNullArgOps($block, $nullOps);
        $this->assertSame([], $nullOps, 'switch lowering must not emit opcodes with null operand slots');
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $nullOps
     */
    private function collectNullArgOps(Block $block, array &$nullOps, string $path = ''): void
    {
        $required = [
            OpCode::TYPE_EQUAL => ['arg1', 'arg2', 'arg3'],
            OpCode::TYPE_JUMPIF => ['arg1'],
            OpCode::TYPE_RETURN => ['arg1'],
            OpCode::TYPE_ECHO => ['arg1'],
        ];
        foreach ($block->opCodes as $i => $op) {
            foreach ($required[$op->type] ?? [] as $argName) {
                if (null === $op->$argName) {
                    $nullOps[] = [$path.'#'.$i, opcode_type_name($op->type), $argName];
                }
            }
            if (null !== $op->block1) {
                $this->collectNullArgOps($op->block1, $nullOps, $path.'>'.$i.'b1');
            }
            if (null !== $op->block2) {
                $this->collectNullArgOps($op->block2, $nullOps, $path.'>'.$i.'b2');
            }
            if (null !== $op->block3) {
                $this->collectNullArgOps($op->block3, $nullOps, $path.'>'.$i.'b3');
            }
        }
    }
}
