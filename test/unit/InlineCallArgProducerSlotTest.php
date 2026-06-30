<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\VM\Variable;

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

    /** Issue #12732 — by-ref sort-family builtins must not defer as sibling inline producers. */
    public function testNatcasesortBeforeArrayValuesImplodeCompilesEagerly(): void
    {
        $code = <<<'PHP'
<?php
$a = ['IMG12', 'img2', 'Img1'];
natcasesort($a);
echo implode(',', array_values($a));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'natcasesort_r.php');

        $initNames = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $initNames[] = $block->constants[$op->arg1]->toString();
            }
        }

        self::assertContains('natcasesort', $initNames, 'fcall inits='.json_encode($initNames));
        ob_start();
        $runtime->run($block);
        self::assertSame('Img1,img2,IMG12', ob_get_clean());
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
                $arraySlot = $op->arg1;
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

    /** Issue #11153 — array_all([], fn) must wire inline [] to arg 0, not swap with closure. */
    public function testArrayAllInlineEmptyArrayUsesArrayAndClosureSlots(): void
    {
        $code = <<<'PHP'
<?php
array_all([], fn ($v) => (bool) $v);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_all_empty_inline.php');

        $arraySlot = null;
        $closureSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
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

    /** Issue #12721 — array_filter([..], fn) must wire inline array to arg 0, closure to arg 1. */
    public function testArrayFilterInlineClosureUsesArrayAndClosureSlots(): void
    {
        $code = <<<'PHP'
<?php
array_filter([1, 2, 3], fn (int $v): bool => $v > 1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_filter_inline_closure.php');

        $arraySlot = null;
        $closureSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
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

    /** Issue #12721 — array_filter inline closure runtime parity. */
    public function testArrayFilterInlineClosureRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
var_export(array_filter([1, 2, 3], fn (int $v): bool => $v > 1));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_filter_inline_closure_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  1 => 2,\n  2 => 3,\n)\n", ob_get_clean());
    }

    /** Issue #11153 — vacuous array_all on inline [] matches Zend. */
    public function testArrayAllInlineEmptyArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
echo array_all([], fn ($v) => (bool) $v) ? 'all' : 'notall', "\n";
echo array_any([], fn ($v) => (bool) $v) ? 'any' : 'notany', "\n";
echo array_find([], fn ($v) => (bool) $v) === null ? 'null' : 'bad', "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_find_family_empty_inline.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("all\nnotany\nnull\n", ob_get_clean());
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

    /** Issue #10981 — closure static locals in var_dump($g(), $g()) evaluate 1 then 2 at runtime. */
    public function testClosureStaticVarDumpMultiArgRuntime(): void
    {
        $code = <<<'PHP'
<?php
$g = function (): int {
    static $n = 0;
    return ++$n;
};
ob_start();
var_dump($g(), $g());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_closure_static.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("int(1)\nint(2)\n", ob_get_clean());
    }

    /** Issue #10917 — sibling str_repeat() producers map to distinct levenshtein() arg slots. */
    public function testLevenshteinDualStrRepeatUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$n = 100;
levenshtein(str_repeat('a', $n), str_repeat('b', $n));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'levenshtein_dual_str_repeat.php');

        $returnSlots = [];
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $sendSlots);
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
        self::assertContains($sendSlots[0], $returnSlots, 'fcall returns='.json_encode($returnSlots));
        self::assertContains($sendSlots[1], $returnSlots, 'fcall returns='.json_encode($returnSlots));
    }

    /** Issue #10917 — levenshtein(str_repeat('a', n), str_repeat('b', n)) runtime parity with Zend. */
    public function testLevenshteinDualStrRepeatRuntime(): void
    {
        $code = <<<'PHP'
<?php
$n = 100;
echo levenshtein(str_repeat('a', $n), str_repeat('b', $n));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'levenshtein_dual_str_repeat_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('100', ob_get_clean());
    }

    /** Issue #13779 — dual inline array_keys() map to distinct array_diff_assoc() arg slots. */
    public function testArrayDiffAssocDualInlineArrayKeysUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
array_diff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_diff_assoc_inline_array_keys.php');

        $returnSlots = [];
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $sendSlots);
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
        self::assertContains($sendSlots[0], $returnSlots, 'fcall returns='.json_encode($returnSlots));
        self::assertContains($sendSlots[1], $returnSlots, 'fcall returns='.json_encode($returnSlots));
    }

    /** Issue #13779 — array_diff_assoc(array_keys(), array_keys()) runtime parity with Zend. */
    public function testArrayDiffAssocDualInlineArrayKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_diff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9])));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_diff_assoc_inline_array_keys.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  1 => 'b',\n)", ob_get_clean());
    }

    /** Issue #10918 — sibling str_repeat() producers map to distinct similar_text() arg slots. */
    public function testSimilarTextDualStrRepeatUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$n = 100;
similar_text(str_repeat('a', $n), str_repeat('b', $n));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'similar_text_dual_str_repeat.php');

        $returnSlots = [];
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $sendSlots);
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #10918 — similar_text(str_repeat('a', n), str_repeat('b', n)) runtime parity with Zend. */
    public function testSimilarTextDualStrRepeatRuntime(): void
    {
        $code = <<<'PHP'
<?php
$n = 100;
echo similar_text(str_repeat('a', $n), str_repeat('b', $n));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'similar_text_dual_str_repeat_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0', ob_get_clean());
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

    /** Issue #13687 — id((object) ['a' => 1]) wires Cast producer, not hoisted Array_ temp. */
    public function testObjectCastArrayLiteralUsesCastProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
function id(object $o): string {
    return get_class($o);
}
id((object) ['a' => 1]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'object_cast_array_call_arg.php');

        $castSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_OBJECT === $op->type) {
                $castSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($castSlot);
        self::assertSame([$castSlot], $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #13687 — id(clone new C()) wires Clone producer, not dead arg temp. */
    public function testCloneNewObjectUsesCloneProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
class C {}
function id(object $o): string {
    return get_class($o);
}
id(clone new C());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'clone_new_call_arg.php');

        $cloneSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLONE === $op->type) {
                $cloneSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($cloneSlot);
        self::assertSame([$cloneSlot], $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #13685 — probe(new ArrayIterator([])) wires New_ producer, not ctor Array_ prelude. */
    public function testSplArrayInlineNewUsesNewProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
function probe(mixed $x): string {
    return get_debug_type($x);
}
probe(new ArrayIterator([]));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'spl_array_inline_new_call_arg.php');

        $newSlots = [];
        $probeSendSlot = null;
        $inProbeCall = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $inProbeCall = true;
            }
            if ($inProbeCall && OpCode::TYPE_ARG_SEND === $op->type) {
                $probeSendSlot = $op->arg1;
                $inProbeCall = false;
            }
        }

        self::assertNotNull($probeSendSlot, 'probe() call must emit ARG_SEND');
        self::assertContains($probeSendSlot, $newSlots, 'probe() must send New_ producer slot, not ctor Array_ prelude');
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

    /** Issue #12824 — var_export([NAN, INF], true) wires Array_ producer, not hoisted ConstFetch temps. */
    public function testVarExportInlineNanInfArrayUsesArrayProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export([NAN, INF], true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_inline_nan_inf_array.php');

        $arraySlot = null;
        $trueSlot = null;
        $sendSlots = [];
        $afterArray = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $op->type) {
                $afterArray = true;
            }
            if ($afterArray && OpCode::TYPE_CONST_FETCH === $op->type) {
                $trueSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot);
        self::assertNotNull($trueSlot);
        self::assertSame([$arraySlot, $trueSlot], $sendSlots, 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('NAN', $out);
        self::assertStringContainsString('INF', $out);
    }

    /** Issue #12764 — inline array of sprintf(NAN/INF) must wire each hoisted ConstFetch, not a prior sibling FuncCall. */
    public function testSprintfNanInfInlineArrayUsesConstFetchProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$lines = [
    sprintf('%F', NAN),
    sprintf('%G', NAN),
];
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'sprintf_nan_inf_inline_array.php');

        $ops = $block->opCodes;
        $nanFetchSlots = [];
        for ($i = 0, $n = \count($ops); $i < $n; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_CONST_FETCH !== $op->type) {
                continue;
            }
            $nameConst = $block->constants[$op->arg2] ?? null;
            if (null === $nameConst || Variable::TYPE_STRING !== $nameConst->type || 'NAN' !== $nameConst->toString()) {
                continue;
            }
            $nanFetchSlots[] = (int) $op->arg1;
            $argSends = [];
            for ($j = $i + 1; $j < $n && \count($argSends) < 2; ++$j) {
                if (OpCode::TYPE_ARG_SEND === $ops[$j]->type) {
                    $argSends[] = (int) $ops[$j]->arg1;
                }
            }
            self::assertCount(2, $argSends, 'expected format+value sends after NAN fetch');
            self::assertSame((int) $op->arg1, $argSends[1], 'NAN fetch slot must feed sprintf value arg');
        }

        self::assertCount(2, $nanFetchSlots, 'NAN fetch slots='.json_encode($nanFetchSlots));
    }

    /** Issue #12764 — sprintf(NAN/INF) inside inline array literal matches Zend at runtime. */
    public function testSprintfNanInfInlineArrayRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_sprintf_nan_case.php');
        self::assertIsString($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_sprintf_nan_case.php');

        ob_start();
        try {
            $runtime->run($block);
            $out = ob_get_clean();
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            ob_end_clean();
            self::fail('sprintf NAN/INF inline array repro exited '.$e->status);
        }

        self::assertSame("ok\n", $out);
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

    /** Issue #8930 — inline enum arrays must not treat numeric keys as enum backing aliases. */
    public function testArrayReplaceInlineEnumLiteralsDistinctKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; case B = 2; }
var_export(array_replace([E::A], [1 => E::B]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_inline_enum.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => \E::A,\n  1 => \E::B,\n)\n", ob_get_clean());
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

    /** Issue #12258 — nested inline array + null replacement must use outer array slot, not inner. */
    public function testArrayReplaceRecursiveNestedInlineNullUsesOuterArraySlot(): void
    {
        $code = <<<'PHP'
<?php
array_replace_recursive(['a' => ['b' => 1]], ['a' => null]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_nested_inline_null.php');

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

        self::assertCount(3, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[1], $sendSlots[0], 'outer nested inline array must feed arg #1');
        self::assertSame($arraySlots[2], $sendSlots[1], 'null overlay array must feed arg #2');
    }

    /** Issue #12258 — array_replace_recursive nested inline null runtime parity with Zend. */
    public function testArrayReplaceRecursiveNestedInlineNullRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => null]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_nested_inline_null_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'a' => NULL,\n)\n", ob_get_clean());
    }

    /** Issue #10230 — array_merge_recursive nested sibling inline literals use outer Array_ roots. */
    public function testArrayMergeRecursiveNestedSiblingInlineLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_merge_recursive_nested_sibling_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'a' => array (\n    'x' => 1,\n    'y' => 2,\n  ),\n)\n", ob_get_clean());
    }

    /** Issue #10809 — inline assoc literal + negative offset + preserve_keys must wire array to arg #0. */
    public function testArraySliceInlineAssocNegativeOffsetPreserveKeysCompile(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_slice(['a' => 1, 'b' => 2, 'c' => 3], -2, 1, true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_inline_assoc_negative.php');

        $arraySlot = null;
        $boolSlot = null;
        $sliceSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $boolSlot = $op->arg1;
            }
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

        self::assertNotNull($arraySlot);
        self::assertCount(4, $sliceSends, 'array_slice arg sends');
        self::assertSame($arraySlot, $sliceSends[0], 'inline array must feed arg #0');
        self::assertNotSame($arraySlot, $sliceSends[1], 'negative offset must not reuse array slot');
        self::assertSame($boolSlot, $sliceSends[3], 'preserve_keys must feed trailing bool');
    }

    /** Issue #10809 — inline assoc literal + negative offset + preserve_keys runtime parity. */
    public function testArraySliceInlineAssocNegativeOffsetPreserveKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_slice(['a' => 1, 'b' => 2, 'c' => 3], -2, 1, true));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_inline_assoc_negative_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'b' => 2,\n)\n", ob_get_clean());
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

    /** Issue #11511 — var_export(inline array union, true) wires Plus result slot, not dead array temps. */
    public function testVarExportArrayUnionReturnTrueUsesPlusResultSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(['a' => 1] + ['b' => 2], true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_union_var_export_return.php');

        $plusSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (OpCode::TYPE_PLUS === $op->type) {
                $plusSlot = $op->arg1;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($plusSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($plusSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));
    }

    /** Issue #11511 — var_export(inline array union, true) runtime parity with Zend. */
    public function testVarExportArrayUnionReturnTrueRuntime(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_var_export_array_union_return.php');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_var_export_array_union_return.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("r='array (", $out);
        self::assertStringContainsString('a', $out);
        self::assertStringContainsString('b', $out);
        self::assertStringNotContainsString('NULL', $out);
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

    /** Issue #9888 / #8796 / #9702 — in_array(enum, [enum, ...]) wires enum needle + inline haystack slots. */
    public function testInArrayEnumNeedleInlineHaystackLooseUsesEnumAndArraySlots(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; case B = 2; }
var_export(in_array(E::A, [E::A, E::B]));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_enum_loose.php');

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
        self::assertCount(2, $inArraySends, 'in_array arg sends='.json_encode($inArraySends));
        self::assertSame($enumFetchSlots[0], $inArraySends[0], 'enum needle slot');
        self::assertSame($arraySlot, $inArraySends[1], 'inline haystack slot');
        self::assertNotSame($inArraySends[0], $inArraySends[1], 'needle and haystack must differ');
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

    /** Issue #10321 — in_array(1, [1,2,3], strict: true) wires inline haystack + named strict slots. */
    public function testInArrayLiteralNeedleInlineHaystackStrictNamedUsesArrayAndBoolSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
var_export(in_array(1, [1, 2, 3], strict: true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_strict_named.php');

        $arraySlot = null;
        $boolSlot = null;
        $inArraySends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $boolSlot) {
                $boolSlot = $op->arg1;
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

        self::assertNotNull($arraySlot);
        self::assertNotNull($boolSlot);
        self::assertCount(3, $inArraySends, 'in_array sends='.json_encode($inArraySends));
        self::assertSame($arraySlot, $inArraySends[1], 'haystack must use inline array slot');
        self::assertSame($boolSlot, $inArraySends[2], 'strict must use hoisted true slot');
        self::assertNotSame($inArraySends[1], $inArraySends[2], 'haystack and strict must differ');
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

    /** Issue #10899 — inline new ArrayIterator to intersection-typed param must wire New_ slot. */
    public function testIntersectionTypedParamInlineNewUsesNewProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

function ic(Countable&Traversable $x): int
{
    return count($x);
}

echo ic(new ArrayIterator([1, 2, 3])), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'intersection_inline_new.php');

        $newSlots = [];
        $icSendSlot = null;
        $inIcCall = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $inIcCall = true;
            }
            if ($inIcCall && OpCode::TYPE_ARG_SEND === $op->type) {
                $icSendSlot = $op->arg1;
                $inIcCall = false;
            }
        }

        self::assertNotNull($icSendSlot, 'ic() call must emit ARG_SEND');
        self::assertContains($icSendSlot, $newSlots, 'ic() must send New_ producer slot, not unbound temp');
    }

    /** Issue #10899 — Countable&Traversable call with inline new ArrayIterator. */
    public function testIntersectionTypedParamInlineNewRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

function ic(Countable&Traversable $x): int
{
    return count($x);
}

echo ic(new ArrayIterator([1, 2, 3])), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'intersection_inline_new_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("3\n", ob_get_clean());
    }

    /** Issue #12916 — new LimitIterator(new ArrayIterator([...]), …) must send inner New_ slot to outer ctor. */
    public function testNestedNewConstructorInlineNewUsesInnerProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

var_export(iterator_to_array(new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1)));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'limititerator_inline_arrayiterator.php');

        $newSlots = [];
        $limitSendSlot = null;
        $newCount = 0;
        $pendingLimitCtor = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
                ++$newCount;
                if (2 === $newCount) {
                    $pendingLimitCtor = true;
                }
            }
            if ($pendingLimitCtor && OpCode::TYPE_ARG_SEND === $op->type && null === $limitSendSlot) {
                $limitSendSlot = $op->arg1;
                $pendingLimitCtor = false;
            }
        }

        self::assertNotNull($limitSendSlot, 'LimitIterator ctor must emit ARG_SEND');
        self::assertContains($limitSendSlot, $newSlots, 'LimitIterator arg #0 must send inner ArrayIterator New_ slot');
    }

    /** Issue #12916 — nested inline new LimitIterator(ArrayIterator) runtime parity. */
    public function testNestedNewConstructorInlineNewRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

var_export(iterator_to_array(new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1)));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'limititerator_inline_arrayiterator_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  1 => 2,\n)", ob_get_clean());
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

    /** Issue #12450 — array_merge(array_keys($src), [...]) wires FuncCall + sibling Array_ producer slots. */
    public function testArrayMergeInlineArrayKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
$src = ['a' => 1, 'b' => 2];
var_export(array_merge(array_keys($src), ['b']));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_merge_inline_array_keys.php');

        $keysReturnSlot = null;
        $mergeSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $mergeSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $keysReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mergeSends[] = $op->arg1;
            }
        }

        self::assertNotNull($keysReturnSlot);
        self::assertCount(2, $mergeSends);
        self::assertSame($keysReturnSlot, $mergeSends[0], 'merge sends='.json_encode($mergeSends));
        self::assertNotSame($keysReturnSlot, $mergeSends[1], 'merge sends='.json_encode($mergeSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("'a'", $out);
        self::assertStringContainsString("'b'", $out);
        self::assertStringNotContainsString("'a', 'b', 'a', 'b'", $out);
    }

    /** Issue #13704 — array_merge(array_keys($src), ['b']) runtime output parity. */
    public function testArrayMergeInlineArrayKeysOutput(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_merge_inline_array_keys.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_merge_inline_array_keys.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 'a'", $out);
        self::assertStringContainsString("1 => 'b'", $out);
        self::assertStringContainsString("2 => 'b'", $out);
        self::assertStringNotContainsString("3 =>", $out);
    }

    /** Issue #13760 — array_merge(['a'=>1], array_keys(...)) wires Array_ + nested FuncCall producer slots. */
    public function testArrayMergeLeadingArrayTrailingArrayKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_merge(['a' => 1], array_keys(['b' => 2])));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_merge_string_key_order.php');

        $leadingArraySlot = null;
        $keysReturnSlot = null;
        $mergeSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $mergeSends = [];
                }
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $leadingArraySlot) {
                $leadingArraySlot = $op->arg1;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $keysReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mergeSends[] = $op->arg1;
            }
        }

        self::assertNotNull($leadingArraySlot);
        self::assertNotNull($keysReturnSlot);
        self::assertCount(2, $mergeSends);
        self::assertSame($leadingArraySlot, $mergeSends[0], 'merge sends='.json_encode($mergeSends));
        self::assertSame($keysReturnSlot, $mergeSends[1], 'merge sends='.json_encode($mergeSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("'a' => 1", $out);
        self::assertStringContainsString("0 => 'b'", $out);
    }

    /** Issue #13778 — array_intersect_assoc(array_keys(...), array_keys(...)) wires both sibling FuncCall slots. */
    public function testArrayIntersectAssocInlineArrayKeysRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_intersect_assoc_inline_array_keys.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_intersect_assoc_inline_array_keys.php');

        $keysReturnSlots = [];
        $intersectSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $intersectSends = [];
                }
            }
            if ($fcallOrdinal <= 2 && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $keysReturnSlots[] = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $intersectSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $keysReturnSlots);
        self::assertCount(2, $intersectSends);
        self::assertSame($keysReturnSlots[0], $intersectSends[0], 'intersect sends='.json_encode($intersectSends));
        self::assertSame($keysReturnSlots[1], $intersectSends[1], 'intersect sends='.json_encode($intersectSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 'a'", $out);
        self::assertStringContainsString("ok", $out);
    }

    /** Issue #13776 — array_combine(array_keys(...), [...]) wires FuncCall + trailing Array_ producer slots. */
    public function testArrayCombineInlineArrayKeysRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_combine(array_keys(['a' => 1, 'b' => 2]), [10, 20]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_combine_inline_array_keys.php');

        $keysReturnSlot = null;
        $combineSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $combineSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $keysReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $combineSends[] = $op->arg1;
            }
        }

        self::assertNotNull($keysReturnSlot);
        self::assertCount(2, $combineSends);
        self::assertSame($keysReturnSlot, $combineSends[0], 'combine sends='.json_encode($combineSends));
        self::assertNotSame($keysReturnSlot, $combineSends[1], 'combine sends='.json_encode($combineSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("'a' => 10", $out);
        self::assertStringContainsString("'b' => 20", $out);
    }

    /** Issue #10093 — array_merge([1], [2]) sibling inline Array_ literals use distinct producer slots. */
    public function testArrayMergeSiblingInlineLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_merge([1], [2]));
echo "\n";
var_export(array_merge(['a' => 1], ['a' => 2]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_merge_inline_literal.php');

        $mergeSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $mergeSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mergeSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $mergeSends);
        self::assertNotSame($mergeSends[0], $mergeSends[1]);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 1,\n  1 => 2,", $out);
        self::assertStringContainsString("'a' => 2,", $out);
    }

    /** Issue #11373 — in_array('md5', hash_algos(), true) nested producer runtime parity with Zend. */
    public function testInArrayNestedHashAlgosRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(in_array('md5', hash_algos(), true));
echo "\n";
$src = ['a' => 1, 'b' => 2];
var_export(in_array('a', array_keys($src), true));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_hash_algos_in_array.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("true\ntrue\n", $out);
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

    /** Issue #11399 — var_export(in_array(..., true), true) wires nested FuncCall + dual ConstFetch true slots. */
    public function testVarExportNestedBuiltinDualTrueLiteralUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(in_array('a', ['a'], true), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_dual_true_literal_nested.php');

        $inArrayReturnSlot = null;
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
                $inArrayReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($inArrayReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($inArrayReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('true', $out);
    }

    /** Issue #13829 — var_export(key($a), true) in concat wires hoisted key() sibling, not ConstFetch true. */
    public function testVarExportNestedArrayPointerBuiltinUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
next($a);
$concat = 'key=' . var_export(key($a), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_array_pointer.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("key=1\n", $out);
    }

    /** Issue #13828 — var_export(current($a), true) in concat after next($a). */
    public function testVarExportNestedCurrentUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
next($a);
$concat = 'current=' . var_export(current($a), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_current.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("current=2\n", $out);
    }

    /** Issue #13901 — var_export($it->current(), true) in concat after next(). */
    public function testVarExportNestedArrayIteratorCurrentUsesMethodCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$it = new ArrayIterator([1, 2, 3]);
$it->next();
$concat = 'cur=' . var_export($it->current(), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_arrayiterator_current.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("cur=2\n", $out);
    }

    /** Issue #13901 — var_export($it->key(), true) in concat after next(). */
    public function testVarExportNestedArrayIteratorKeyUsesMethodCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$it = new ArrayIterator([1, 2, 3]);
$it->next();
$concat = 'key=' . var_export($it->key(), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_arrayiterator_key.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("key=1\n", $out);
    }

    /** Issue #13899 — var_export($g->current(), true) in concat after next() (Zend/zend_generators.c). */
    public function testVarExportNestedGeneratorCurrentUsesMethodCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
function g() { yield 10; yield 20; yield 30; }
$g = g();
$g->next();
$g->next();
$concat = 'val=' . var_export($g->current(), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_generator_current.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("val=30\n", $out);
    }

    /** Issue #13899 — var_export($g->key(), true) in concat after next() (Zend/zend_generators.c). */
    public function testVarExportNestedGeneratorKeyUsesMethodCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
function g() { yield 10; yield 20; yield 30; }
$g = g();
$g->next();
$concat = 'key=' . var_export($g->key(), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_generator_key.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("key=1\n", $out);
    }

    /** Issue #13830 — var_export(next($a), true) in concat after prior next($a). */
    public function testVarExportNestedNextUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
next($a);
$concat = 'next=' . var_export(next($a), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_next.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("next=3\n", $out);
    }

    /** Issue #13903 — var_export(prev($a), true) in concat after prior next($a). */
    public function testVarExportNestedPrevUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
next($a);
$concat = 'prev=' . var_export(prev($a), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_prev.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("prev=1\n", $out);
    }

    /** Issue #13831 — var_export(end($a), true) in concat when pointer already at end. */
    public function testVarExportNestedEndSecondCallUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
end($a);
$concat = 'end=' . var_export(end($a), true);
echo $concat, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_end.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("end=3\n", $out);
    }

    /** Issue #11400 — print_r(in_array(..., true), true) wires nested FuncCall + dual ConstFetch true slots. */
    public function testPrintRNestedBuiltinDualTrueLiteralUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo print_r(in_array('x', ['x'], true), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'print_r_dual_true_literal_nested.php');

        $inArrayReturnSlot = null;
        $printRSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $printRSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $inArrayReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $printRSends[] = $op->arg1;
            }
        }

        self::assertNotNull($inArrayReturnSlot);
        self::assertCount(2, $printRSends);
        self::assertSame($inArrayReturnSlot, $printRSends[0], 'arg sends='.json_encode($printRSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('1', $out);
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

    /** Issue #13636 — substr(sprintf('%o', fileperms($path)), -N) nested int builtin arg slot + runtime. */
    public function testSubstrNestedSprintfFilepermsUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$tmp = tempnam(sys_get_temp_dir(), 'phpc');
chmod($tmp, 0644);
echo substr(sprintf('%o', fileperms($tmp)), -4);
unlink($tmp);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_substr_sprintf_fileperms.php');

        $substrInitIndex = null;
        $sprintfInitIndex = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $idx => $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (4 === $fcallOrdinal) {
                    $sprintfInitIndex = $idx;
                }
                if (5 === $fcallOrdinal) {
                    $substrInitIndex = $idx;
                }
            }
        }

        self::assertNotNull($substrInitIndex);
        self::assertNotNull($sprintfInitIndex);
        self::assertLessThan($substrInitIndex, $sprintfInitIndex, 'nested sprintf must INIT before outer substr');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('0644', $out);
    }

    /** Issue #13662 — str_contains($last['message'], $fn . '():') must not mis-wire call args. */
    public function testStrContainsArrayDimFetchAndInlineConcatCallArgSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function assert_warning(string $function, callable $call): void
{
    $call();
    $last = error_get_last();
    $message = $last['message'];
    if (!\is_string($message)) {
        throw new \RuntimeException('message not string: ' . get_debug_type($message));
    }
    if (!str_contains($message, $function . '():')) {
        throw new \RuntimeException('missing function prefix');
    }
    if (!str_contains($message, 'No ending delimiter')) {
        throw new \RuntimeException('missing delimiter text');
    }
}
assert_warning('preg_match', static fn () => preg_match('/[', 'x'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_contains_dim_concat_call_arg.php');
        $runtime->run($block);
        $this->addToAssertionCount(1);
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

    /** Issue #13790 — preg_match(chained concat pattern with NUL) wires final Concat slot. */
    public function testPregMatchChainedConcatPatternWithNulUsesFinalConcatSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
echo preg_match('/' . chr(0) . '/', "a\0b") ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'preg_match_concat_nul_pattern.php');

        $concatSlots = [];
        $sendSlots = [];
        $this->collectOpCodesFromBlock($block, $concatSlots, $sendSlots);

        self::assertGreaterThanOrEqual(2, \count($concatSlots), 'concat slots='.json_encode($concatSlots));
        self::assertContains($concatSlots[\count($concatSlots) - 1], $sendSlots, 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('1', $out);
    }

    /** Issue #13458 — chained inline concat path args must wire final Concat slot, not first. */
    public function testFopenChainedInlineConcatPathUsesFinalConcatSlot(): void
    {
        $code = <<<'PHP'
<?php
fopen('/tmp/maint_' . 99 . '/sub/file.txt', 'r');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'fopen_chained_concat_path.php');

        $concatSlots = [];
        $sendSlots = [];
        $this->collectOpCodesFromBlock($block, $concatSlots, $sendSlots);

        self::assertCount(2, $concatSlots, 'concat slots='.json_encode($concatSlots));
        self::assertSame($concatSlots[\count($concatSlots) - 1], $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));

        $runtimeWarn = new Runtime();
        $warnBlock = $runtimeWarn->parseAndCompile(<<<'PHP'
<?php
@fopen('/tmp/maint_' . 99 . '/sub/file.txt', 'r');
PHP, 'fopen_chained_concat_path_warn.php');
        ob_start();
        $runtimeWarn->run($warnBlock);
        ob_get_clean();
        $err = error_get_last();
        self::assertNotNull($err);
        self::assertStringContainsString('/tmp/maint_99/sub/file.txt', $err['message'] ?? '');
    }

    /**
     * @param list<int|string> $concatSlots
     * @param list<int|string> $sendSlots
     */
    private function collectOpCodesFromBlock(Block $block, array &$concatSlots, array &$sendSlots): void
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONCAT === $op->type) {
                $concatSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        foreach ($block->blocks as $child) {
            if ($child instanceof Block) {
                $this->collectOpCodesFromBlock($child, $concatSlots, $sendSlots);
            }
        }
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

    /** Issue #12007 — filter_var() ConstFetch + nested options Array_ map to filter and options slots. */
    public function testFilterVarConstFetchAndNestedOptionsArraySlots(): void
    {
        $code = <<<'PHP'
<?php
filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_nested_options.php');

        $constSlot = null;
        $outerArraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constSlot) {
                $constSlot = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $outerArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($constSlot);
        self::assertNotNull($outerArraySlot);
        self::assertCount(3, $sendSlots, 'filter_var arg sends='.json_encode($sendSlots));
        self::assertSame($constSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($outerArraySlot, $sendSlots[2] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #12007 — filter_var() nested inline options runtime parity with Zend. */
    public function testFilterVarNestedOptionsArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$r = filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']]);
echo $r === 'abc' ? "ok\n" : "bad\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_nested_options_runtime.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("ok\n", $out);
    }

    /** Issue #12326 — filter_var() flags options array maps ConstFetch + Array_ to filter/options slots. */
    public function testFilterVarFlagsOptionsArraySlots(): void
    {
        $code = <<<'PHP'
<?php
filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_flags_options.php');

        $constSlot = null;
        $arraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constSlot) {
                $constSlot = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($constSlot);
        self::assertNotNull($arraySlot);
        self::assertCount(3, $sendSlots, 'filter_var arg sends='.json_encode($sendSlots));
        self::assertSame($constSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlot, $sendSlots[2] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #12326 — filter_var() flags options array runtime parity with Zend. */
    public function testFilterVarFlagsOptionsArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$r = filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
echo null === $r ? "ok\n" : "bad\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_flags_options_runtime.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("ok\n", $out);
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

    /** Issue #11304 — array_map(fn, [new C()]) wires closure + outer Array_, not embedded New_. */
    public function testArrayMapClosureInlineObjectArrayUsesClosureAndArraySlots(): void
    {
        $code = <<<'PHP'
<?php
array_map(fn($x) => $x, [new stdClass()]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_closure_object_inline.php');

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

        self::assertCount(1, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'callback and array must use distinct slots');
        self::assertSame($arraySlots[0], $sendSlots[1], 'array arg must use outer Array_ slot');
    }

    /** Issue #11304 — array_map(fn, [new C()]) runtime parity with Zend. */
    public function testArrayMapClosureInlineObjectArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
$r = array_map(fn($x) => $x, [new stdClass()]);
echo is_object($r[0]) ? "object\n" : "not_object\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_closure_object_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("object\n", ob_get_clean());
    }

    /** Issue #10094 — array_map(fn, [...], [...]) wires closure + both inline array slots. */
    public function testArrayMapDualInlineArrayLiteralUsesAllSlots(): void
    {
        $code = <<<'PHP'
<?php
array_map(fn ($a, $b) => $a + $b, [1, 2], [3, 4]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_dual_inline.php');

        $arraySlots = [];
        $closureSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $closureSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $arraySlots, 'array inits='.json_encode($arraySlots));
        self::assertNotNull($closureSlot);
        self::assertCount(3, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($closureSlot, $sendSlots[0]);
        self::assertSame($arraySlots[0], $sendSlots[1]);
        self::assertSame($arraySlots[1], $sendSlots[2]);
    }

    /** Issue #10094 — array_map with two inline array literals runtime parity. */
    public function testArrayMapDualInlineArrayLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_map(fn ($a, $b) => $a + $b, [1, 2], [3, 4]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_dual_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => 4,\n  1 => 6,\n)\n", ob_get_clean());
    }

    /** Issue #13812 — array_map(null, [...], [...]) wires null + both inline array slots. */
    public function testArrayMapNullZipInlineArrayLiteralUsesAllSlots(): void
    {
        $code = <<<'PHP'
<?php
array_map(null, [1, 2], [3, 4]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_null_zip_inline.php');

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
        self::assertCount(3, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[0], $sendSlots[1]);
        self::assertSame($arraySlots[1], $sendSlots[2]);
    }

    /** Issue #13812 — array_map(null) zip runtime parity. */
    public function testArrayMapNullZipInlineArrayLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_map(null, [1, 2], [3, 4]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_null_zip_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => array (\n    0 => 1,\n    1 => 3,\n  ),\n  1 => array (\n    0 => 2,\n    1 => 4,\n  ),\n)\n", ob_get_clean());
    }

    /** Issue #11187 — rename($src, $dst) after file_put_contents must send path locals, not int returns. */
    public function testRenameAfterFilePutContentsUsesNamedPathSlots(): void
    {
        $code = <<<'PHP'
<?php
$base = 'test/repro/rename_overwrite_fixture';
$src = $base . '/src.txt';
$dst = $base . '/dst.txt';
file_put_contents($src, 'source');
file_put_contents($dst, 'existing');
rename($src, $dst);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'rename_after_file_put_contents.php');

        $srcSlot = null;
        $dstSlot = null;
        $fpcReturnSlots = [];
        $renameSends = [];
        $fcallOrdinal = 0;
        $lastCallSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                $lastCallSends = [];
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $srcSlot) {
                $srcSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $dstSlot) {
                $dstSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && $fcallOrdinal <= 2) {
                $fpcReturnSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $lastCallSends[] = $op->arg1;
            }
        }
        $renameSends = $lastCallSends;

        self::assertNotNull($srcSlot);
        self::assertNotNull($dstSlot);
        self::assertCount(2, $renameSends, 'rename arg sends='.json_encode($renameSends));
        self::assertSame([$srcSlot, $dstSlot], $renameSends, 'must not wire file_put_contents return ints');
        self::assertNotContains($renameSends[0], $fpcReturnSlots, 'fpc returns='.json_encode($fpcReturnSlots));
    }

    /** Issue #11187 — rename onto existing destination overwrites (php-src ext/standard/file.c). */
    public function testRenameOverwriteExistingDestinationRuntime(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_rename_overwrite.php');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_rename_overwrite.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("rename=true", $out);
        self::assertStringContainsString("src_exists=false", $out);
        self::assertStringContainsString("dst='source'", $out);
    }

    /** Issue #11387 — inline ENT_QUOTES | ENT_SUBSTITUTE must feed flags arg, not ENT_SUBSTITUTE alone. */
    public function testHtmlspecialcharsInlineBitmaskFlagsArgSend(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$s = '<>&"';
htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'htmlspecialchars_bitmask.php');

        $bitwiseOrSlot = null;
        $sendSlots = [];
        $captureSends = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_BITWISE_OR === $op->type) {
                $bitwiseOrSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $captureSends = true;
                $sendSlots = [];
                continue;
            }
            if ($captureSends && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
                continue;
            }
            if ($captureSends && (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type)) {
                break;
            }
        }

        self::assertNotNull($bitwiseOrSlot, 'expected TYPE_BITWISE_OR slot');
        self::assertSame($bitwiseOrSlot, $sendSlots[1] ?? null, 'flags arg sends='.json_encode($sendSlots));
    }

    /** Issue #11409 — chown($path, getmyuid()) wires nested int into trailing arg slot. */
    public function testChownNamedPathNestedGetmyuidArgSend(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$path = '/nope/1';
chown($path, getmyuid());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'chown_nested.php');

        $getmyuidReturnSlot = null;
        $chownSendSlots = [];
        $pendingSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $getmyuidReturnSlot) {
                $getmyuidReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if ([] !== $pendingSends) {
                    $chownSendSlots = $pendingSends;
                }
                $pendingSends = [];
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $pendingSends[] = $op->arg1;
            }
        }
        if ([] !== $pendingSends) {
            $chownSendSlots = $pendingSends;
        }

        self::assertNotNull($getmyuidReturnSlot);
        self::assertSame($getmyuidReturnSlot, $chownSendSlots[1] ?? null, 'chown sends='.json_encode($chownSendSlots));
    }

    /** Issue #11321 — iterator_to_array(new ArrayObject([...]), false) uses New_ slot, not ctor Array_. */
    public function testIteratorToArrayInlineNewWithFalsePreserveKeysUsesNewSlot(): void
    {
        $code = <<<'PHP'
<?php
echo json_encode(array_values(iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]), false)), JSON_THROW_ON_ERROR), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'iterator_to_array_preserve_false.php');

        $newSlot = null;
        $sendSlots = [];
        $capture = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $capture = true;
                $sendSlots = [];
                continue;
            }
            if ($capture && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
                continue;
            }
            if ($capture && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertNotNull($newSlot);
        self::assertSame($newSlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        self::assertSame("[1,2]\n", ob_get_clean());
    }

    /** Issue #11694 — call_user_func_array(C::class.'::ok', []) wires Concat slot to arg #0. */
    public function testCallUserFuncArrayInlineClassConcatCallableSlot(): void
    {
        $code = <<<'PHP'
<?php
class CufaInlineClassMethodProbe {
    public static function ok(): string {
        return 'ok';
    }
}
echo call_user_func_array(CufaInlineClassMethodProbe::class.'::ok', []);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'call_user_func_array_class_string.php');

        $concatSlot = null;
        $arraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONCAT === $op->type) {
                $concatSlot = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($concatSlot, 'concat slot missing');
        self::assertNotNull($arraySlot, 'array slot missing');
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($concatSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlot, $sendSlots[1], 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('ok', $out);
    }

    /** Issue #12730 — sibling flat inline array literals must map to distinct arg slots. */
    public function testArrayDiffInlineFlatLiteralsUseDistinctArraySlots(): void
    {
        $code = <<<'PHP'
<?php
array_diff(['1', '2'], [1]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_diff_inline_flat.php');

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

    /** Issue #12730 — array_diff() inline literal runtime parity with Zend. */
    public function testArrayDiffInlineFlatLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_diff(['1', '2'], [1]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_diff_inline_flat_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  1 => '2',\n)\n", ob_get_clean());
    }

    /** Issue #12729 — nested sibling inline array literals must map to distinct outer roots. */
    public function testArrayReplaceRecursiveNestedSiblingInlineLiteralsUseDistinctArraySlots(): void
    {
        $code = <<<'PHP'
<?php
array_replace_recursive(['a' => ['b' => 1]], ['a' => ['c' => 2]]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_nested_sibling_inline.php');

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

    /** Issue #12729 — array_replace_recursive nested sibling inline literal runtime parity. */
    public function testArrayReplaceRecursiveNestedSiblingInlineLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['c' => 2]]));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_nested_sibling_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  'a' => array (\n    'b' => 1,\n    'c' => 2,\n  ),\n)\n", ob_get_clean());
    }

    /** Issue #12008 — nested inline array + 4th positional arg must not steal Array_ slots for literals. */
    public function testHttpBuildQueryNestedInlineArrayFourPositionalArgsRuntime(): void
    {
        $code = <<<'PHP'
<?php
echo http_build_query(['a' => ['x', 'y']], '', '&', PHP_QUERY_RFC1738), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'http_build_query_nested_four_args.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("a%5B0%5D=x&a%5B1%5D=y\n", ob_get_clean());
    }

    /** Bootstrap M4 (#2880): production driver must VM-parse (composer closure assign slot #5644). */
    public function testBinCompilePhpVmParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root.'/bin/compile.php');
        $block = (new Runtime())->parseAndCompile($source, $root.'/bin/compile.php');
        $this->assertNotNull($block);
    }

    /** Issue #12766 — array_all(null, static fn) via variable call must wire null to arg 0, closure to arg 1. */
    public function testArrayAllNullStaticClosureVariableCallUsesNullAndClosureSlots(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'array_all';
try {
    $fn(null, static fn () => true);
} catch (TypeError $e) {
    echo $e->getMessage();
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_all_var_null_closure.php');

        $nullSlot = null;
        $closureSlot = null;
        $sendSlots = [];
        $walk = function (Block $b) use (&$walk, &$nullSlot, &$closureSlot, &$sendSlots): void {
            static $seen = [];
            $id = spl_object_id($b);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_CONST_FETCH === $op->type && null === $nullSlot) {
                    $nullSlot = $op->arg1;
                }
                if (OpCode::TYPE_CLOSURE === $op->type) {
                    $closureSlot = $op->arg1;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type) {
                    $sendSlots[] = $op->arg1;
                }
                if (OpCode::TYPE_TRY === $op->type && $op->block1 instanceof Block) {
                    $walk($op->block1);
                }
                if (OpCode::TYPE_CLOSURE === $op->type && $op->block1 instanceof Block) {
                    $walk($op->block1);
                }
            }
        };
        $walk($block);

        self::assertNotNull($nullSlot);
        self::assertNotNull($closureSlot);
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($nullSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertSame($closureSlot, $sendSlots[1], 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        self::assertSame(
            'array_all(): Argument #1 ($array) must be of type array, null given',
            ob_get_clean()
        );
    }

    /** Issue #12766 — foreach-key variable call array_find family null TypeError messages. */
    public function testArrayFindFamilyNullStaticClosureVariableCallRuntime(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_array_all_null_typeerror.php');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_all_null_typeerror.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    /** Issue #5644 — named closure locals must use assign.result slots across echo-separated var_export calls. */
    public function testNamedClosureLocalSurvivesEchoBetweenVarExportCalls(): void
    {
        $code = <<<'PHP'
<?php
$cmp = static fn ($a, $b) => $a <=> $b;
$keycmp = static fn ($a, $b) => strcmp((string) $a, (string) $b);
var_export(array_udiff([1, 2], [2, 3], $cmp));
echo "\n";
var_export(array_uintersect([1, 2, 3], [2, 3, 4], $cmp));
echo "\n";
var_export(array_udiff_uassoc(['a' => 1], ['a' => 1], $cmp, $keycmp));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'named_closure_var_export_echo.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("array (\n)", $out);
    }

    /** Issue #11070 — date_sunrise(time(), SUNFUNCS_RET_STRING, …) wires FuncCall + ConstFetch producer slots. */
    public function testDateSunriseInlineTimeAndSunfuncsConstUsesProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
date_sunrise(time(), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sunrise_inline_const.php');

        $timeReturnSlot = null;
        $constSlot = null;
        $sunriseSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $sunriseSends = [];
                }
            }
            if (1 === $fcallOrdinal && (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type)) {
                $timeReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constSlot) {
                $constSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sunriseSends[] = $op->arg1;
            }
        }

        self::assertNotNull($timeReturnSlot);
        self::assertNotNull($constSlot);
        self::assertCount(6, $sunriseSends, 'arg sends='.json_encode($sunriseSends));
        self::assertSame($timeReturnSlot, $sunriseSends[0], 'arg sends='.json_encode($sunriseSends));
        self::assertSame($constSlot, $sunriseSends[1], 'arg sends='.json_encode($sunriseSends));
    }

    /** Issue #11070 — date_sunrise inline SUNFUNCS_RET_STRING returns HH:MM string at runtime. */
    public function testDateSunriseInlineSunfuncsConstStringRuntime(): void
    {
        $code = <<<'PHP'
<?php
$r = date_sunrise(time(), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
echo is_string($r) ? 'string' : gettype($r), "\n";
echo preg_match('/^\d{2}:\d{2}$/', (string) $r) ? "hhmm\n" : "bad\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sunrise_inline_const_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("string\nhhmm\n", ob_get_clean());
    }

    /** Issue #12009 — hoisted FuncCall + ConstFetch siblings with embedded middle literal (try body). */
    public function testJsonDecodeInlineStrRepeatJsonThrowOnErrorDepthRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_json_decode_throw_on_depth.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'json_decode_depth_throw.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    /** Issue #13423 — preg_split(..., -1, PREG_SPLIT_*) nested in check() wires limit/flags slots. */
    public function testPregSplitNegativeLimitWithFlagsNestedCallArg(): void
    {
        $code = <<<'PHP'
<?php
check(
    'b',
    preg_split('/( )/', 'a b c', -1, PREG_SPLIT_DELIM_CAPTURE),
    ['a', ' ', 'b', ' ', 'c']
);
check('a', preg_split('/ /', 'a b c', -1), ['a', 'b', 'c']);
function check(string $label, mixed $got, mixed $expected): void
{
    if ($got !== $expected) {
        throw new \LogicException('mismatch: '.$label);
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'preg_split_nested_limit_flags.php');
        $runtime->run($block);
        $this->addToAssertionCount(1);
    }

    /** Issue #13424 — explode(..., -1) nested in check() wires limit + result slots. */
    public function testExplodeNegativeLimitNestedCallArg(): void
    {
        $code = <<<'PHP'
<?php
check('explode(-1)', explode('a', 'bab', -1), ['b']);
check('explode(-2)', explode('-', 'a-b-c-d', -2), ['a', 'b']);
function check(string $label, mixed $got, mixed $expected): void
{
    if ($got !== $expected) {
        throw new \LogicException('mismatch: '.$label);
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'explode_nested_negative_limit.php');
        $runtime->run($block);
        $this->addToAssertionCount(1);
    }

    /** Issue #13617 — filter_var(nested subject, FILTER_*) inside nested array literal rows. */
    public function testFilterVarNestedSubjectInLabeledArrayLiteralSlots(): void
    {
        $code = <<<'PHP'
<?php
$checks = [
  ['label1', filter_var('127.0.0.1', FILTER_VALIDATE_IP), '127.0.0.1'],
  ['label2', filter_var(sprintf('%s', '127.0.0.1'), FILTER_VALIDATE_IP), '127.0.0.1'],
];
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_nested_subject_labeled_array.php');

        $constSlots = [];
        $sprintfReturnSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && \count($sendSlots) >= 4 && null === $sprintfReturnSlot) {
                $sprintfReturnSlot = $op->arg1;
            }
        }

        self::assertCount(2, $constSlots);
        self::assertCount(6, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($sprintfReturnSlot, $sendSlots[4], 'nested subject must use sprintf return slot');
        self::assertSame($constSlots[1], $sendSlots[5], 'filter constant must use second ConstFetch slot');
    }

    /** Issue #13617 — filter_var(nested subject, FILTER_*) runtime parity in labeled array rows. */
    public function testFilterVarNestedSubjectInLabeledArrayLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
$checks = [
  ['label1', filter_var('127.0.0.1', FILTER_VALIDATE_IP), '127.0.0.1'],
  ['label2', filter_var(sprintf('%s', '127.0.0.1'), FILTER_VALIDATE_IP), '127.0.0.1'],
];
foreach ($checks as [$label, $got, $want]) {
    if ($got !== $want) {
        throw new \LogicException('mismatch: '.$label);
    }
}
echo "ok\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_nested_subject_labeled_array_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    /** Issue #13660 — glob($dir.'/*', GLOB_MARK) wires Concat + ConstFetch hoisted producer slots. */
    public function testGlobInlineConcatAndGlobMarkConstUsesProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$dir = 'test/compliance/cases/stdlib/glob_onlydir_fixture';
glob($dir.'/*', GLOB_MARK);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'glob_inline_concat_glob_mark.php');

        $concatSlots = [];
        $constSlots = [];
        $sendSlots = [];
        $this->collectOpCodesFromBlock($block, $concatSlots, $sendSlots);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlots[] = $op->arg1;
            }
        }

        self::assertNotEmpty($concatSlots);
        self::assertNotEmpty($constSlots);
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($concatSlots[0], $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertSame($constSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #13684 — array_slice($a, array_search(...)) wires hoisted Array_ to arg0, nested FuncCall to arg1. */
    public function testArraySliceNestedIntBuiltinOffsetUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_slice([1, 2, 3, 4], array_search(3, [1, 2, 3, 4])));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_nested_offset.php');

        $searchReturnSlot = null;
        $sliceArraySlot = null;
        $sliceSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $sliceArraySlot) {
                $sliceArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $sliceSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $searchReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sliceSends[] = $op->arg1;
            }
        }

        self::assertNotNull($sliceArraySlot);
        self::assertNotNull($searchReturnSlot);
        self::assertCount(2, $sliceSends, 'array_slice arg sends='.json_encode($sliceSends));
        self::assertSame($sliceArraySlot, $sliceSends[0], 'array arg must use hoisted Array_ slot');
        self::assertSame($searchReturnSlot, $sliceSends[1], 'offset must use array_search return slot');
    }

    /** Issue #13684 — array_slice nested offset runtime parity. */
    public function testArraySliceNestedIntBuiltinOffsetRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_slice([1, 2, 3, 4], array_search(3, [1, 2, 3, 4])));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_slice_nested_offset_runtime.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('3', $out);
        self::assertStringContainsString('4', $out);
        self::assertStringNotContainsString('TypeError', $out);
    }

    /** Issue #13694 — comparison inside call arg must not bind as (call($x)) !== false. */
    public function testNotIdenticalInsideCallArgUsesComparisonSlot(): void
    {
        $code = <<<'PHP'
<?php
var_dump(var_dump(1) !== false);
$r = (1 !== 2);
var_dump($r);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'comparison_call_arg.php');

        $notIdenticalResultSlot = null;
        $outerSendSlot = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type && null === $notIdenticalResultSlot) {
                $notIdenticalResultSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type && 2 === $fcallOrdinal) {
                $outerSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($notIdenticalResultSlot);
        self::assertSame($notIdenticalResultSlot, $outerSendSlot);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('bool(true)', $out);
    }

    /** Issue #13703 — array_column() inline haystack literal runtime parity with Zend. */
    public function testArrayColumnInlineHaystackTwoArgRuntime(): void
    {
        $code = <<<'PHP'
<?php
$r = array_column([['n' => 'a'], ['n' => 'b']], 'n');
var_export($r);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_column_inline_haystack.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => 'a',\n  1 => 'b',\n)\n", ob_get_clean());
    }

    /** Issue #13800 — extract(inline array) then var_export($local) must not re-compile extract for var_export arg. */
    public function testExtractInlineArrayThenVarExportNamedLocal(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$a = 1;
extract(['a' => 2]);
var_export($a);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'extract_inline_var_export.php');

        $initNames = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $initNames[] = $block->constants[$op->arg1]->toString();
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertSame(['extract', 'var_export'], $initNames, 'fcall inits='.json_encode($initNames));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'extract and var_export must use distinct arg slots');

        ob_start();
        $runtime->run($block);
        self::assertSame("2\n", ob_get_clean());
    }
}
