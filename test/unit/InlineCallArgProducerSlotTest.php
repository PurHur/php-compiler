<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class InlineCallArgProducerSlotTest extends TestCase
{
    public function testVarDumpReceivesPropertyAndStaticDefaultSlots(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }

class C {
    public int $n = E::A->value;
    public static int $s = E::A->value;
}

function f(int $n = E::A->value): int { return $n; }

var_dump((new C())->n, C::$s, f());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'inline_call_arg.php');

        $propSlot = null;
        $staticSlot = null;
        $fSlot = null;
        $sendSlots = [];
        $fcallReturnSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_PROPERTY_FETCH === $op->type) {
                $propSlot = $op->arg1;
            }
            if (OpCode::TYPE_STATIC_PROPERTY_FETCH === $op->type) {
                $staticSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $fcallReturnSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        $fSlot = $fcallReturnSlots[1] ?? null;

        self::assertSame([$propSlot, $staticSlot, $fSlot], $sendSlots, 'fcall='.json_encode($fcallReturnSlots));
    }

    /** Issue #9074 — by-ref builtin then read mutated named local after return capture. */
    public function testByRefBuiltinThenVarDumpNamedLocalUsesArraySlot(): void
    {
        $code = <<<'PHP'
<?php
$a = [3, 1, 2];
$r = sort($a);
var_dump($r);
var_dump($a);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'byref_return.php');

        $arraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg2;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot);
        self::assertSame($arraySlot, $sendSlots[2] ?? null, 'arg sends='.json_encode($sendSlots));
    }
}
