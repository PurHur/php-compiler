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
        self::assertCount(4, $sendSlots, 'array_slice arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($arraySlot, $sendSlots[3] ?? null, 'preserve_keys must not reuse array slot');
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

    /** Issue #9684 — enum case ->name/->value in direct call args use property-fetch slot. */
    public function testVarDumpEnumCaseMagicPropertyUsesPropertyFetchSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_dump(E::A->name);
var_dump(E::A->value);
enum S { case A; }
var_dump(S::A->name);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_magic_call_arg.php');

        $propSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_PROPERTY_FETCH === $op->type) {
                $propSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertSame($propSlots, $sendSlots, 'prop='.json_encode($propSlots).' sends='.json_encode($sendSlots));
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

    /** Issue #10143 — var_export((string) NAN) wires Cast producer, not dead arg temp. */
    public function testStringCastNanConstantUsesCastProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
var_export((string) NAN);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'string_cast_nan_call_arg.php');

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

    /** Issue #10231 — sibling inline Array_ producers map to distinct array_replace arg slots. */
    public function testArrayReplaceDualInlineArrayLiteralsUseBothArraySlots(): void
    {
        $code = <<<'PHP'
<?php
array_replace(['a' => 1], ['a' => 2, 'b' => 3]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_inline_literals.php');

        $arraySlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[0], $sendSlots[0], 'first inline array must feed arg #1');
        self::assertSame($arraySlots[1], $sendSlots[1], 'second inline array must feed arg #2');
    }

    /** Issue #10231 — array_replace inline literal first arg runtime parity with Zend. */
    public function testArrayReplaceInlineLiteralFirstArgRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_replace(['a' => 1], ['a' => 2, 'b' => 3]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'a' => 2,\n  'b' => 3,\n)\n", ob_get_clean());
    }

    /** Issue #10196 — nested inline array literals map to outermost Array_ per arg slot. */
    public function testArrayReplaceRecursiveNestedInlineLiteralsUseRootArraySlots(): void
    {
        $code = <<<'PHP'
<?php
array_replace_recursive(['a' => ['b' => 1]], ['a' => ['b' => 2, 'c' => 3]]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_nested_inline.php');

        $arraySlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(4, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[1], $sendSlots[0], 'first nested inline array root must feed arg #1');
        self::assertSame($arraySlots[3], $sendSlots[1], 'second nested inline array root must feed arg #2');
    }

    /** Issue #10196 — array_replace_recursive nested inline literal runtime parity with Zend. */
    public function testArrayReplaceRecursiveNestedInlineLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['b' => 2, 'c' => 3]]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_nested_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'a' => array (\n    'b' => 2,\n    'c' => 3,\n  ),\n)\n", ob_get_clean());
    }

    /** Issue #10612 — null element in inline Array_ must not steal first call-arg producer slot. */
    public function testArrayReplaceRecursiveInlineNullElementUsesBothArraySlots(): void
    {
        $code = <<<'PHP'
<?php
array_replace_recursive(['a' => 1], ['a' => null]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_inline_null.php');

        $arraySlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[0], $sendSlots[0], 'first inline array must feed arg #1');
        self::assertSame($arraySlots[1], $sendSlots[1], 'second inline array must feed arg #2');
    }

    /** Issue #10612 — array_replace_recursive inline null element runtime parity with Zend. */
    public function testArrayReplaceRecursiveInlineNullElementRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_replace_recursive(['a' => 1], ['a' => null]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_inline_null_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'a' => NULL,\n)\n", ob_get_clean());
    }

    /** Issue #10229 — var_export(array_slice($local, -2, 2, true)) folds negative offset + preserve_keys. */
    public function testVarExportArraySliceNegativeOffsetPreserveKeysCompile(): void
    {
        $code = <<<'PHP'
<?php
$a = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];
var_export(array_slice($a, -2, 2, true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_negative_offset.php');

        $sliceSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $sliceSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sliceSends[] = $op->arg1;
            }
        }

        self::assertCount(4, $sliceSends, 'array_slice arg sends');
    }

    public function testVarExportArraySliceNegativeOffsetPreserveKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
$a = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];
var_export(array_slice($a, -2, 2, true));
echo "\n";
$b = ['a', 'b', 'c', 'd', 'e'];
var_export(array_slice($b, 1, -2));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_negative_offset.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  2 => 'c',\n  3 => 'd',\n)\narray (\n  0 => 'b',\n  1 => 'c',\n)\n",
            ob_get_clean()
        );
    }

    /** Issue #10490 — inline array union + must wire Plus result slot, not dead array temps. */
    public function testArrayUnionInlineLiteralUsesPlusResultSlot(): void
    {
        $code = <<<'PHP'
<?php
var_export([1 => 'a'] + [2 => 'b']);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_union_inline_literals.php');

        $plusSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_PLUS === $op->type) {
                $plusSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($plusSlot);
        self::assertSame([$plusSlot], $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #10490 — array union inline literal runtime parity with Zend. */
    public function testArrayUnionInlineLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export([1 => 'a'] + [2 => 'b']);
echo "\n";
var_export(['a' => 1] + ['a' => 2]);
echo "\n";
var_export([] + [1]);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_union_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  1 => 'a',\n  2 => 'b',\n)\narray (\n  'a' => 1,\n)\narray (\n  0 => 1,\n)\n",
            ob_get_clean()
        );
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

    /** Issue #9152 — second call is_subclass_of must receive hoisted UnitEnum::class slot. */
    public function testIsSubclassOfAfterIsAUsesClassConstFetchSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
$child = 'E';
var_export(is_a($child, UnitEnum::class, true));
var_export(is_subclass_of($child, UnitEnum::class));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'is_subclass_enum_class_string.php');

        $classConstSlots = [];
        $argSendGroups = [];
        $currentSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                $classConstSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if ([] !== $currentSends) {
                    $argSendGroups[] = $currentSends;
                }
                $currentSends = [];
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $currentSends[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                if ([] !== $currentSends) {
                    $argSendGroups[] = $currentSends;
                }
                $currentSends = [];
            }
        }

        self::assertCount(2, $classConstSlots, 'UnitEnum::class fetches for is_a and is_subclass_of');
        $isSubclassSends = null;
        foreach ($argSendGroups as $group) {
            if (2 === \count($group) && ($group[1] ?? null) === $classConstSlots[1]) {
                $isSubclassSends = $group;
                break;
            }
        }
        self::assertNotNull($isSubclassSends, 'groups='.json_encode($argSendGroups));
        self::assertSame($classConstSlots[1], $isSubclassSends[1]);
    }

    /** Issue #9575 — spaceship + from() call-arg must send int result, not dead enum-case temp (#9030). */
    public function testVarDumpSpaceshipFromSendsSpaceshipResultSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
enum E: int { case A = 1; }
var_dump(E::A <=> E::from(1));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_spaceship_var_dump.php');

        $spaceshipSlot = null;
        $varDumpSend = null;
        $seenSpaceship = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_SPACESHIP === $op->type) {
                $spaceshipSlot = $op->arg1;
                $seenSpaceship = true;
            }
            if ($seenSpaceship && OpCode::TYPE_ARG_SEND === $op->type && null === $varDumpSend) {
                $varDumpSend = $op->arg1;
            }
        }

        self::assertNotNull($spaceshipSlot, 'spaceship result slot must be lowered');
        self::assertSame($spaceshipSlot, $varDumpSend, 'var_dump arg send='.$varDumpSend);
    }

    /** Issue #9660 — var_export(enum === scalar, true) wires Identical producer, not hoisted true literal. */
    public function testVarExportEnumIdenticalScalarUsesIdenticalProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
echo var_export(E::A === 1, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_identical_var_export.php');

        $identSlot = null;
        $constSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_IDENTICAL === $op->type) {
                $identSlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($identSlot);
        self::assertCount(2, $sendSlots);
        self::assertSame($identSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($identSlot, $sendSlots[1], 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #5901 — var_export(C::AR[0] === E::X, true) wires Identical producer, not ClassConstFetch prelude. */
    public function testVarExportClassConstArrayDimIdenticalUsesIdenticalProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case X = 1; case Y = 2; }
class C { public const AR = [E::X, E::Y]; }
echo var_export(C::AR[0] === E::X, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_const_enum_identical_var_export.php');

        $identSlot = null;
        $classConstSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_IDENTICAL === $op->type) {
                $identSlot = $op->arg1;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type && null === $classConstSlot) {
                $classConstSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($identSlot);
        self::assertNotNull($classConstSlot);
        self::assertCount(2, $sendSlots);
        self::assertSame($identSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($classConstSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #9888 / #8796 — in_array(enum, [enum, ...], true) wires enum needle + inline haystack slots. */
    public function testInArrayEnumNeedleInlineHaystackStrictUsesEnumAndArraySlots(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; case B = 2; }
var_export(in_array(E::A, [E::A, E::B], true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_enum_strict.php');

        $enumFetchSlots = [];
        $arraySlot = null;
        $inArraySends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                $enumFetchSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $inArraySends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $inArraySends[] = $op->arg1;
            }
        }

        self::assertNotEmpty($enumFetchSlots);
        self::assertNotNull($arraySlot);
        self::assertCount(3, $inArraySends, 'in_array arg sends='.json_encode($inArraySends));
        self::assertSame($enumFetchSlots[0], $inArraySends[0], 'enum needle slot');
        self::assertSame($arraySlot, $inArraySends[1], 'inline haystack slot');
        self::assertNotSame($inArraySends[0], $inArraySends[1], 'needle and haystack must differ');
    }

    /** Issue #9462 — array_unique($local, SORT_REGULAR) wires hoisted zero-valued SORT_* slot. */
    public function testArrayUniqueNamedLocalSortRegularUsesConstFetchSlot(): void
    {
        $code = <<<'PHP'
<?php
$in = [1, 1, 2];
var_dump(array_unique($in, SORT_REGULAR));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_unique_sort_regular_local.php');

        $arraySlot = null;
        $sortSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg2;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $sortSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot);
        self::assertNotNull($sortSlot);
        self::assertSame($arraySlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($sortSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #9462 — array_unique($local, SORT_REGULAR) runtime parity with Zend. */
    public function testArrayUniqueNamedLocalSortRegularRuntime(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public int $v) {}
}
$in = [new C(1), new C(1), new C(2)];
$out = array_unique($in, SORT_REGULAR);
echo count($out), "\n";
foreach ($out as $o) {
    echo $o->v, "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_unique_sort_regular_object_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("2\n1\n2\n", ob_get_clean());
    }

    /** Issue #9904 — invokeArgs(new C(), [...]) must send New_ object slot, not sibling Array_ producer. */
    public function testInvokeArgsNewObjectThenArrayUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
class Greeter {
    public function hello(string $name): string {
        return "hi {$name}";
    }
}
$rm = new ReflectionMethod(Greeter::class, 'hello');
$rm->invokeArgs(new Greeter(), ['world']);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'reflection_invoke_args_new_array.php');

        $newSlots = [];
        $arraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        $invokeArgsSends = \array_slice($sendSlots, -2);

        self::assertCount(2, $invokeArgsSends);
        self::assertContains($invokeArgsSends[0], $newSlots, 'invokeArgs object arg must use New_ slot');
        self::assertSame($arraySlot, $invokeArgsSends[1], 'invokeArgs array arg must use Array_ slot');
        self::assertNotSame($invokeArgsSends[0], $invokeArgsSends[1]);
    }

    /** Issue #9904 — invokeArgs(new C(), [...]) runtime parity with Zend. */
    public function testInvokeArgsNewObjectThenArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
class Greeter {
    public function hello(string $name): string {
        return "hi {$name}";
    }
}
$rm = new ReflectionMethod(Greeter::class, 'hello');
echo $rm->invokeArgs(new Greeter(), ['world']), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'reflection_invoke_args_new_array_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("hi world\n", ob_get_clean());
    }

    /** Issue #10373 — var_export(substr(...), true) wires nested FuncCall + ConstFetch producer slots. */
    public function testVarExportNestedBuiltinReturnTrueUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(substr('hello', 0, -2), true);
echo "\n";
echo var_export(array_keys(['a' => 1, 'b' => 2]), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_builtin_return_true.php');

        $substrReturnSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $substrReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($substrReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($substrReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("'hel'", $out);
        self::assertStringContainsString("'a'", $out);
        self::assertStringContainsString("'b'", $out);
    }

    /** Issue #10351 — var_export(array_pad([...], -N, 0), true) wires nested FuncCall + Array_ + UnaryMinus. */
    public function testVarExportArrayPadNegativeLengthUsesNestedFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(array_pad([1], -3, 0), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_array_pad_negative.php');

        $padReturnSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $padReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($padReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($padReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('0', $out);
        self::assertStringContainsString('1', $out);
    }

    /** Issue #10495 — var_export(get_debug_type(null), true) wires nested scalar-return FuncCall producer. */
    public function testVarExportNestedScalarBuiltinUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(get_debug_type(null), true);
echo "\n";
echo var_export(gettype(null), true);
echo "\n";
echo var_export(json_encode(null), true);
echo "\n";
echo var_export(get_class(new stdClass()), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_string_builtin_call_arg.php');

        $debugTypeReturnSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $debugTypeReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($debugTypeReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($debugTypeReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("'null'", $out);
        self::assertStringContainsString("'NULL'", $out);
        self::assertStringContainsString("'stdClass'", $out);
    }

    /** Issue #10673 — substr(sprintf(...), -N) wires nested FuncCall + UnaryMinus producer slots. */
    public function testSubstrNestedSprintfUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo substr(sprintf('%o', 33188), -4);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_substr_sprintf.php');

        $sprintfReturnSlot = null;
        $substrSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $substrSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $sprintfReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $substrSends[] = $op->arg1;
            }
        }

        self::assertNotNull($sprintfReturnSlot);
        self::assertCount(2, $substrSends);
        self::assertSame($sprintfReturnSlot, $substrSends[0], 'arg sends='.json_encode($substrSends));
        self::assertNotSame($substrSends[0], $substrSends[1], 'arg sends='.json_encode($substrSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('0644', $out);
    }

    /** Issue #10663 — hoisted null ConstFetch must not replace concat result call arg. */
    public function testVarExportConcatNullUsesConcatSlotNotHoistedNull(): void
    {
        $code = <<<'PHP'
<?php
var_export('a' . null);
var_export(null . 'b');
var_export(null . null);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'concat_null_call_arg.php');

        $concatSlots = [];
        $nullConstSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $nullConstSlot) {
                $nullConstSlot = $op->arg1;
            }
            if (OpCode::TYPE_CONCAT === $op->type) {
                $concatSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(3, $concatSlots, 'concat slots='.json_encode($concatSlots));
        self::assertCount(3, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($concatSlots, $sendSlots);
        self::assertNotSame($nullConstSlot, $sendSlots[0] ?? null);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("'a'", $out);
        self::assertStringContainsString("'b'", $out);
        self::assertStringContainsString("''", $out);
    }

    /** Issue #10453 — hoisted PASSWORD_BCRYPT ConstFetch maps to arg #2 when trailing Array_ options literal. */
    public function testPasswordHashConstFetchAndArrayOptionSlots(): void
    {
        $code = <<<'PHP'
<?php
password_hash('secret', PASSWORD_BCRYPT, ['cost' => 10]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'password_hash_options.php');

        $constSlot = null;
        $arraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constSlot) {
                $constSlot = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($constSlot);
        self::assertNotNull($arraySlot);
        self::assertCount(3, $sendSlots, 'password_hash arg sends='.json_encode($sendSlots));
        self::assertSame($constSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlot, $sendSlots[2] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #10566 — count([nested inline], COUNT_RECURSIVE) wires outer Array_ + mode const. */
    public function testCountNestedInlineLiteralRecursiveUsesArrayAndModeSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$n = count([1, [2, 3]], COUNT_RECURSIVE);
echo "count={$n}\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'count_recursive_inline_literal.php');

        $arraySlots = [];
        $modeSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $modeSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertNotNull($modeSlot);
        self::assertSame($arraySlots[1], $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($modeSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #10566 — count([nested inline], COUNT_RECURSIVE) runtime parity with Zend. */
    public function testCountNestedInlineLiteralRecursiveRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$n = count([1, [2, 3]], COUNT_RECURSIVE);
echo "count={$n}\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'count_recursive_inline_literal_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("count=4\n", ob_get_clean());
    }
}
