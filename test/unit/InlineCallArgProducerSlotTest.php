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

    /** Issue #9463 — sibling closure __invoke FuncCall producers map to distinct var_dump arg slots. */
    public function testVarDumpReceivesDistinctClosureCallProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$g = function (): int {
    static $n = 0;
    return ++$n;
};
var_dump($g(), $g());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'closure_static_multi_arg.php');

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

    /** Issue #9456 — literal string key must not consume hoisted Array_+Assign producer slot. */
    public function testArrayKeyExistsLiteralKeyUsesStringNotHoistedArray(): void
    {
        $code = <<<'PHP'
<?php
$a = ['k' => 1, '' => 2];
var_dump(array_key_exists('k', $a));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_key_exists_string_key.php');

        $arraySlot = null;
        $akeSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $akeSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $akeSends[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot);
        self::assertNotSame($arraySlot, $akeSends[0] ?? null, 'literal key must not reuse hoisted array slot');
        self::assertCount(2, $akeSends, 'array_key_exists arg sends='.json_encode($akeSends));
    }

    /** Issue #9483 — var_dump($s--) wires PostDec/PreDec producer result slots. */
    public function testVarDumpEmptyStringDecrementUsesIncDecProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$s = '';
var_dump($s--);
$s = '';
var_dump(--$s);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'empty_string_decrement_call_arg.php');

        $postDecSlot = null;
        $preDecSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_POST_DEC === $op->type) {
                $postDecSlot = $op->arg1;
            }
            if (OpCode::TYPE_PRE_DEC === $op->type) {
                $preDecSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($postDecSlot);
        self::assertNotNull($preDecSlot);
        self::assertSame($postDecSlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($preDecSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #9479 — inline (int) enum cast producer maps to var_dump arg slot. */
    public function testVarDumpIntCastEnumCaseUsesCastProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_dump((int) E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_int_cast_call_arg.php');

        $castSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_INT === $op->type) {
                $castSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($castSlot);
        self::assertSame([$castSlot], $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #9504 — var_export((string) new C()) wires Cast producer, not dead arg temp. */
    public function testStringCastNewObjectUsesCastProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
class C implements Stringable {
    public function __toString(): string {
        return 'x';
    }
}
var_export((string) new C());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'string_cast_new_call_arg.php');

        $castSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_STRING === $op->type) {
                $castSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($castSlot);
        self::assertSame([$castSlot], $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Bootstrap helloworld — New_ then static MethodCall (null var) must not TypeError in producer filter. */
    public function testNewStaticMethodCallCompilesWithoutOperandNullTypeError(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public static function m(): int { return 1; }
}
var_dump((new C())::m());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'new_static_method_call_arg.php');
        self::assertNotEmpty($block->opCodes);
    }

    /** Issue #9428 — var_dump((new C())->m()) wires MethodCall return, not New_ object. */
    public function testVarDumpNewMethodCallUsesMethodReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function m(): int { return 1; } }
class C { use T { m as private p; } public function call(): int { return $this->p(); } }
var_dump((new C())->call());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'new_method_call_call_arg.php');

        $newSlot = null;
        $methodReturnSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type && null === $newSlot) {
                $newSlot = $op->arg1;
            }
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                $methodReturnSlot = null;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $methodReturnSlot) {
                $methodReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($newSlot);
        self::assertNotNull($methodReturnSlot);
        self::assertSame($methodReturnSlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($newSlot, $sendSlots[0] ?? null, 'must not pass New_ object to var_dump');
    }

    /** Issue #9548 — hoisted CAL_GREGORIAN (0) maps to first cal_to_jd arg, not a dead temp. */
    public function testZeroValuedConstFetchFirstBuiltinArg(): void
    {
        $code = <<<'PHP'
<?php
cal_to_jd(CAL_GREGORIAN, 6, 6, 2026);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'cal_to_jd_zero_const.php');

        $constSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($constSlot, 'CAL_GREGORIAN const fetch must be lowered');
        self::assertSame($constSlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
    }
}
