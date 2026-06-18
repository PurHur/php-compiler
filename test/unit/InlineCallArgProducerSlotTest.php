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

    /** Issue #9154 — inline arrow callback on array_any when array arg is a named local. */
    public function testArrayAnyInlineClosureUsesSecondArgSlot(): void
    {
        $code = <<<'PHP'
<?php
$arr = [1, 2, 3];
array_any($arr, fn ($v) => $v > 2);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_any_closure.php');

        $arraySlot = null;
        $closureSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg2;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $closureSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot);
        self::assertNotNull($closureSlot);
        self::assertSame($arraySlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($closureSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #9351 — sibling MethodCall producers map to distinct var_dump arg slots. */
    public function testVarDumpReceivesDistinctMethodCallProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function f(): int {
        static $n = 0;
        return ++$n;
    }
}
$c = new C();
var_dump($c->f(), $c->f());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'static_method_local.php');

        $returnSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $sendSlots);
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
        self::assertContains($sendSlots[0], $returnSlots, 'fcall returns='.json_encode($returnSlots));
        self::assertContains($sendSlots[1], $returnSlots, 'fcall returns='.json_encode($returnSlots));
    }

    /** Issue #9335 — var_dump(instanceof) wires boolean InstanceOf producer slot. */
    public function testVarDumpInstanceOfUsesProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_dump(E::A instanceof UnitEnum);
var_dump(E::A instanceof BackedEnum);
var_dump(E::A instanceof E);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_instanceof.php');

        $instanceofSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INSTANCEOF === $op->type) {
                $instanceofSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertSame($instanceofSlots, $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #9324 — hoisted Array_/ConstFetch producers map to dead call-arg temps. */
    public function testArraySliceBoolLiteralSendsArrayThenTrue(): void
    {
        $code = <<<'PHP'
<?php
array_slice([0, 1, 2, 3], 1, 2, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'bool_literal_builtin.php');

        $arraySlot = null;
        $boolSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $boolSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot);
        self::assertNotNull($boolSlot);
        self::assertSame($arraySlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($boolSlot, $sendSlots[3] ?? null, 'arg sends='.json_encode($sendSlots));
    }
}
