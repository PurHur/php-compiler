<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCfg\Operand;
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


    /** Issue #27347 — consecutive array_any with inline Array_ + arrow must not steal prior var_dump. */
    public function testConsecutiveArrayAnyInlineLiteralArrowRuns(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = <<<'PHP'
<?php
var_dump(array_any([1, 2, 3], fn($v) => $v > 5));
var_dump(array_any([1, 2, 3], fn($v) => $v > 1));
var_dump(array_all([1, 2, 3], fn($v) => $v > 0));
var_dump(array_find([1, 2, 3], fn($v) => $v === 2));
PHP;
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'array_any_consecutive_inline.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertSame("bool(false)\nbool(true)\nbool(true)\nint(2)\n", $out);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #27347 — consecutive array_filter inline Array_ + arrow (no PROFILE gate). */
    public function testConsecutiveArrayFilterInlineLiteralArrowRuns(): void
    {
        $code = <<<'PHP'
<?php
var_dump(array_filter([1, 2, 3], fn($v) => $v > 5));
var_dump(array_filter([1, 2, 3], fn($v) => $v > 1));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_filter_consecutive_inline.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('int(2)', $out);
        self::assertStringContainsString('int(3)', $out);
        self::assertStringNotContainsString('TypeError', $out);
    }

    /** Issue #27347 / #15490 — consecutive FuncCall haystacks must keep str_split EXEC, not prior var_dump. */
    public function testConsecutiveArrayFilterFuncCallHaystackUsesProducerExecSlot(): void
    {
        $code = <<<'PHP'
<?php
var_dump(array_filter(explode(',', 'a,b'), static fn($x) => true));
var_dump(array_filter(str_split('12a'), is_numeric(...)));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_filter_consecutive_funcall.php');

        $strSplitExec = null;
        $filterHaystacks = [];
        $pending = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $pending = isset($block->constants[$op->arg1])
                    ? strtolower($block->constants[$op->arg1]->toString())
                    : '';
                continue;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && 'str_split' === $pending) {
                $strSplitExec = (string) $op->arg1;
                $pending = null;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type && 'array_filter' === $pending) {
                $filterHaystacks[] = (string) $op->arg1;
                $pending = null;
            }
        }
        self::assertNotNull($strSplitExec);
        self::assertCount(2, $filterHaystacks);
        self::assertSame($strSplitExec, $filterHaystacks[1], 'haystacks='.json_encode($filterHaystacks));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('string(1) "a"', $out);
        self::assertStringContainsString('string(1) "1"', $out);
        self::assertStringNotContainsString('TypeError', $out);
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

    /** Issue #15151 — inline first array_multisort operand must not share assign-in-call companion slot. */
    public function testArrayMultisortInlineFirstArrayUsesDistinctSendSlots(): void
    {
        $code = <<<'PHP'
<?php
array_multisort([3, 1, 2], $labels = ['c', 'a', 'b']);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_multisort_inline_companion.php');

        $initArraySlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        $companionRhsSlot = $initArraySlots[1] ?? null;

        self::assertCount(2, $sendSlots);
        self::assertNotNull($companionRhsSlot);
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
        self::assertSame($companionRhsSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        ob_end_clean();
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

    /** Issue #17948 — array_filter(explode(...), fn(...)) wires haystack + closure slots. */
    public function testArrayFilterInlineHaystackClosureUsesCorrectSlots(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_filter_inline_haystack_closure.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_filter_inline_haystack_closure.php');

        $haystackReturnSlot = null;
        $closureSlot = null;
        $filterSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $closureSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $filterSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $haystackReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $filterSends[] = $op->arg1;
            }
        }

        self::assertNotNull($closureSlot);
        self::assertNotNull($haystackReturnSlot);
        self::assertCount(2, $filterSends, 'filter sends='.json_encode($filterSends));
        self::assertSame($haystackReturnSlot, $filterSends[0], 'haystack slot');
        self::assertSame($closureSlot, $filterSends[1], 'callback slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('ok', $out);
    }

    /**
     * Issue #27344 — consecutive var_dump(array_filter($cv, fn)) must send the CV slot, not the
     * prior var_dump EXEC_RETURN (null haystack TypeError).
     */
    public function testArrayFilterConsecutiveExprClosureUsesCvHaystackSlots(): void
    {
        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
$b = [1, 2, 3];
var_dump(array_filter($a, static fn ($v): bool => $v > 5));
var_dump(array_filter($b, static fn ($v): bool => $v > 1));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_filter_consecutive_expr_closure_27344.php');

        $assignVarSlots = [];
        $filterOrdinal = 0;
        $secondFilterSends = [];
        $captureSecond = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type) {
                $assignVarSlots[] = $op->arg2;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $name = $block->constants[$op->arg1]->toString();
                if ('array_filter' === $name) {
                    ++$filterOrdinal;
                    $captureSecond = (2 === $filterOrdinal);
                    if ($captureSecond) {
                        $secondFilterSends = [];
                    }
                } else {
                    $captureSecond = false;
                }
            }
            if ($captureSecond && OpCode::TYPE_ARG_SEND === $op->type) {
                $secondFilterSends[] = $op->arg1;
            }
            if ($captureSecond && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $captureSecond = false;
            }
        }

        self::assertCount(2, $assignVarSlots, 'expected $a and $b assigns');
        self::assertCount(2, $secondFilterSends, 'second filter sends='.json_encode($secondFilterSends));
        self::assertSame(
            $assignVarSlots[1],
            $secondFilterSends[0],
            'second haystack must be $b CV, not prior var_dump result'
        );

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('array(0)', $out);
        self::assertStringContainsString('int(2)', $out);
        self::assertStringContainsString('int(3)', $out);
    }

    /** Issue #17950 — usort($a = explode(...), fn) wires assign result + closure, runtime by-ref Error. */
    public function testUsortInlineAssignByRefUsesAssignAndClosureSlots(): void
    {
        $code = <<<'PHP'
<?php
usort($items = explode(',', '3,1,2'), static fn ($a, $b): int => $a <=> $b);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'usort_inline_assign_slots.php');

        $assignSlot = null;
        $closureSlot = null;
        $usortSends = [];
        $fcallOrdinal = 0;
        $lastAssignDest = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $closureSlot = $op->arg1;
            }
            if (OpCode::TYPE_ASSIGN === $op->type) {
                $lastAssignDest = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $assignSlot = $lastAssignDest;
                    $usortSends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $usortSends[] = $op->arg1;
            }
        }

        self::assertNotNull($closureSlot);
        self::assertNotNull($assignSlot);
        self::assertCount(2, $usortSends, 'usort sends='.json_encode($usortSends));
        self::assertSame($assignSlot, $usortSends[0], 'assign result slot');
        self::assertSame($closureSlot, $usortSends[1], 'callback slot');
        self::assertNotSame($closureSlot, $usortSends[0], 'closure must not feed arg #0');
    }

    public function testUsortInlineAssignByRefRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_usort_inline_assign_byref.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_usort_inline_assign_byref.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('ok', $out);
    }

    /** Issue #11153 — vacuous array_all on inline [] matches Zend. */
    public function testArrayAllInlineEmptyArrayRuntime(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
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

    /** Issue #14958 — var_dump(is_countable(null), …, is_countable(new ArrayObject())) wires all sibling slots. */
    public function testIsCountableVarDumpMultiArgWithNewPrelude(): void
    {
        $code = <<<'PHP'
<?php
var_dump(is_countable(null), is_countable([]), is_countable(new ArrayObject()));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'is_countable_var_dump_multi_arg.php');

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
        $varDumpSends = \array_slice($sendSlots, -3);
        self::assertCount(3, $varDumpSends);
        self::assertNotSame($varDumpSends[0], $varDumpSends[1], 'distinct slots 0/1');
        self::assertNotSame($varDumpSends[0], $varDumpSends[2], 'distinct slots 0/2');
        self::assertNotSame($varDumpSends[1], $varDumpSends[2], 'distinct slots 1/2');
        foreach ($varDumpSends as $slot) {
            self::assertContains($slot, $returnSlots, 'fcall returns='.json_encode($returnSlots));
        }

        ob_start();
        $runtime->run($block);
        self::assertSame("bool(false)\nbool(true)\nbool(true)\n", ob_get_clean());
    }

    /** Issue #15646 — var_dump(property_exists(), isset()) on uninitialized typed property. */
    public function testVarDumpPropertyExistsIssetUninitTypedProperty(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public int $x;
}
$o = new C();
var_dump(property_exists($o, 'x'), isset($o->x));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_dump_property_exists_isset.php');

        $returnSlots = [];
        $issetSlots = [];
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ISSET === $op->type) {
                $issetSlots[] = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_PROPERTY_FETCH === $op->type) {
                self::fail('must not emit PROPERTY_FETCH for var_dump isset arg');
            }
        }
        self::assertCount(2, $sendSlots);
        self::assertContains($sendSlots[0], $returnSlots, 'property_exists return feeds arg 0');
        self::assertContains($sendSlots[1], $issetSlots, 'isset return feeds arg 1');

        ob_start();
        $runtime->run($block);
        self::assertSame("bool(true)\nbool(false)\n", ob_get_clean());
    }

    /** Issue #17555 — var_export(isset($obj->typed), true) must export bool, not __set_state. */
    public function testVarExportIssetUninitTypedPropertyUsesBoolSlot(): void
    {
        $code = <<<'PHP'
<?php
class T {
    public int $i;
}
$t = new T();
echo var_export(isset($t->i), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_isset_typed.php');

        $issetSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ISSET === $op->type) {
                $issetSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $sendSlots = [];
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        self::assertNotEmpty($issetSlots);
        self::assertContains($sendSlots[0] ?? null, $issetSlots, 'isset bool feeds var_export arg 0');

        ob_start();
        $runtime->run($block);
        self::assertSame('false', ob_get_clean());
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

    /** Issue #15488 — array_intersect(str_split(str_repeat(...)), str_split(str_repeat(...))) wires outer producers. */
    public function testArrayIntersectDiffInlineNestedStrSplitRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_intersect_diff_inline_nested.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_intersect_diff_inline_nested.php');

        $splitReturnSlots = [];
        $intersectSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (5 === $fcallOrdinal) {
                    $intersectSends = [];
                }
            }
            if (\in_array($fcallOrdinal, [2, 4], true) && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $splitReturnSlots[] = $op->arg1;
            }
            if (5 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $intersectSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $splitReturnSlots);
        self::assertCount(2, $intersectSends);
        sort($splitReturnSlots);
        sort($intersectSends);
        self::assertSame($splitReturnSlots, $intersectSends, 'intersect sends='.json_encode($intersectSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('ok', $out, 'inline array_intersect/diff repro runtime (#16050)');

        // Variable form runtime parity (#15488 follow-up).
        $variableCode = <<<'PHP'
<?php
$left = str_split(str_repeat('ab', 1));
$right = str_split(str_repeat('a', 1));
echo array_intersect($left, $right) === ['a'] ? 'ok' : 'fail';
PHP;
        $variableBlock = $runtime->parseAndCompile($variableCode, 'array_intersect_variable_runtime.php');
        ob_start();
        $runtime->run($variableBlock);
        self::assertStringContainsString('ok', ob_get_clean());
    }

    /** Issue #15487 — array_map(intval(...), str_split(str_repeat(...))) wires FCC + haystack slots. */
    public function testArrayMapFccInlineNestedHaystackRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_map_fcc_inline_nested.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_map_fcc_inline_nested.php');

        $fccSlot = null;
        $splitReturnSlot = null;
        $mapSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FROM_CALLABLE === $op->type) {
                $fccSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $mapSends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $splitReturnSlot = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mapSends[] = $op->arg1;
            }
        }

        self::assertNotNull($fccSlot);
        self::assertNotNull($splitReturnSlot);
        self::assertCount(2, $mapSends, 'map sends='.json_encode($mapSends));
        self::assertSame($fccSlot, $mapSends[0], 'callback slot');
        self::assertSame($splitReturnSlot, $mapSends[1], 'haystack slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('ok', $out);
    }

    /** Issue #15490 — array_filter(str_split(...), is_numeric(...)) wires haystack + FCC callback slots. */
    public function testArrayFilterFccInlineNestedHaystackRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_filter_fcc_inline_nested.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_filter_fcc_inline_nested.php');

        $fccSlot = null;
        $splitReturnSlot = null;
        $filterSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FROM_CALLABLE === $op->type && 2 === $fcallOrdinal) {
                $fccSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $filterSends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $splitReturnSlot = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $filterSends[] = $op->arg1;
            }
        }

        self::assertNotNull($fccSlot);
        self::assertNotNull($splitReturnSlot);
        self::assertCount(2, $filterSends, 'filter sends='.json_encode($filterSends));
        self::assertSame($splitReturnSlot, $filterSends[0], 'haystack slot');
        self::assertSame($fccSlot, $filterSends[1], 'callback slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('ok', $out);
    }

    /** Issue #9072 — preg_replace_callback_array(['/pat/' => fn(...)], $subj) wires INIT_ARRAY not closure slot. */
    public function testPregReplaceCallbackArrayInlineClosureMapRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro-maintainer/preg_replace_callback_array.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'preg_replace_callback_array.php');

        $closureSlot = null;
        $initArraySlot = null;
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $closureSlot = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($closureSlot);
        self::assertNotNull($initArraySlot);
        self::assertCount(2, $sendSlots, 'sends='.json_encode($sendSlots));
        self::assertSame((string) $initArraySlot, (string) $sendSlots[0], 'pattern map slot');
        self::assertNotSame((string) $closureSlot, (string) $sendSlots[0], 'closure must not feed arg #0');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("a[1]b[2]\n", $out);
    }

    /** Issue #19697 — preg_replace_callback_array([...=>fn()], $s, -1, &$count) wires UnaryMinus limit not Array_. */
    public function testPregReplaceCallbackArrayInlineUnaryLimitCountRuntime(): void
    {
        $code = <<<'PHP'
<?php
$count = 0;
$out = preg_replace_callback_array(['/a/' => fn($m) => 'X'], 'aa', -1, $count);
echo $out, '|', $count, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'preg_replace_callback_array_limit_count.php');

        $initArraySlot = null;
        $unarySlot = null;
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_UNARY_MINUS === $op->type || OpCode::TYPE_MINUS === $op->type) {
                $unarySlot = $op->arg1;
            }
            // Folded unary may be a constant INT slot — detect via ARG_SEND distinct from array.
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($initArraySlot);
        self::assertCount(4, $sendSlots, 'sends='.json_encode($sendSlots));
        self::assertSame((string) $initArraySlot, (string) $sendSlots[0], 'pattern map');
        self::assertNotSame((string) $initArraySlot, (string) $sendSlots[2], 'limit must not reuse Array_ slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("XX|2\n", $out);
    }

    /** Issue #14119 — var_dump(acosh(), asinh(), atanh()) sibling scalar-literal producers need distinct slots. */
    public function testVarDumpAcoshAsinhAtanhUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
var_dump(acosh(1.5), asinh(1.5), atanh(0.5));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'acosh_asinh_atanh_var_dump.php');

        $returnSlots = [];
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (4 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if ($fcallOrdinal <= 3 && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (4 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertCount(3, $returnSlots, 'fcall returns='.json_encode($returnSlots));
        self::assertCount(3, $sendSlots);
        self::assertSame($returnSlots, $sendSlots, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #14119 — var_dump(acosh(), asinh(), atanh()) runtime matches Zend. */
    public function testVarDumpAcoshAsinhAtanhRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_acosh_asinh_atanh.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_acosh_asinh_atanh.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('float(0.962423650119207)', $out);
        self::assertStringContainsString('float(1.1947632172871094)', $out);
        self::assertStringContainsString('float(0.5493061443340548)', $out);
        self::assertStringNotContainsString('NULL', $out);
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

    /** Issue #16300 — array_udiff(array_keys(), array_keys(), 'strcmp') wires distinct sibling slots. */
    public function testArrayUdiffDualInlineArrayKeysUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
array_udiff(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), 'strcmp');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_udiff_inline_array_keys.php');

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

        self::assertCount(3, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
        self::assertContains($sendSlots[0], $returnSlots, 'fcall returns='.json_encode($returnSlots));
        self::assertContains($sendSlots[1], $returnSlots, 'fcall returns='.json_encode($returnSlots));
    }

    /** Issue #16300 — array_udiff(array_keys(), array_keys(), 'strcmp') runtime parity with Zend. */
    public function testArrayUdiffDualInlineArrayKeysRuntime(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_array_udiff_inline_array_keys.php');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_udiff_inline_array_keys.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
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

    /** Issue #15476 — similar_text(str_repeat(), str_repeat(), $p) preserves by-ref percent with distinct producer slots. */
    public function testSimilarTextDualStrRepeatWithByRefPercentRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$p = 0.0;
$c = similar_text(str_repeat('a', 5), str_repeat('a', 4), $p);
echo $c, ':', $p, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'similar_text_dual_str_repeat_byref.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("4:88.888888888889\n", ob_get_clean());
    }

    /** Issue #15476 — sibling str_repeat producers each FUNCCALL_EXEC_RETURN before similar_text sends. */
    public function testSimilarTextDualStrRepeatWithByRefPercentUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$p = 0.0;
similar_text(str_repeat('a', 5), str_repeat('a', 4), $p);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'similar_text_dual_str_repeat_byref_slots.php');

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

        self::assertCount(3, $sendSlots);
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
        self::assertContains($sendSlots[0], $returnSlots);
        self::assertContains($sendSlots[1], $returnSlots);
    }

    /** Issue #25672 — sibling next() MethodCall producers must not share ARG_SEND slots. */
    public function testShowReceivesDistinctNextMethodCallProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function next(): int {
        static $n = 0;
        return ++$n;
    }
}
$c = new C();
function show($a, $b) { echo "$a,$b\n"; }
show($c->next(), $c->next());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'next_method_call_args.php');

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

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("1,2\n", $out);
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

    /** Issue #9479 / #15982 — inline (int) enum cast producer maps to var_dump arg slot. */
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

    /** Issue #15982 — var_export((int) E::A) must send cast result, not enum const fetch. */
    public function testVarExportIntCastEnumCaseUsesCastProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_export((int) E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_int_cast_var_export.php');

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

    /** Issue #17551 — (object) array literal as call arg must compile (TYPE_CAST_OBJECT prelude slot). */
    public function testArraySpliceObjectCastPreludeCompilesAndSendsCastSlot(): void
    {
        $code = <<<'PHP'
<?php
array_splice((object) [1, 2, 3], 1, 1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_splice_object_cast.php');

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
        self::assertSame($castSlot, $sendSlots[0] ?? null, 'arg sends='.json_encode($sendSlots));
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

    /** Issue #16227 — (new C())->f(E::A) wires hoisted enum case slot, not inline-new receiver. */
    public function testInlineNewMethodCallEnumCaseArgUsesEnumConstSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; }
class C { public function f(E $e): void {} }
(new C())->f(E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'inline_new_enum_method_arg.php');

        $newSlot = null;
        $enumSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlot = $op->arg1;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                $enumSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($newSlot);
        self::assertNotNull($enumSlot);
        self::assertSame([$enumSlot], $sendSlots, 'new='.$newSlot.' enum='.$enumSlot.' sends='.json_encode($sendSlots));
        self::assertNotSame($newSlot, $enumSlot);
    }

    /** Issue #10286 — nullsafe enum ?-> property in call args wires NullsafePropertyFetch slot. */
    public function testVarExportNullsafeEnumCasePropertyUsesNullsafeFetchSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_export(E::A?->name);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nullsafe_enum_call_arg.php');

        $nullsafeSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NULLSAFE === $op->type) {
                $nullsafeSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        if ([] !== $sendSlots) {
            self::assertSame($nullsafeSlots, $sendSlots, 'nullsafe='.json_encode($nullsafeSlots).' sends='.json_encode($sendSlots));
        } else {
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertSame("'A'", trim($out));
        }
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

    /** Issue #13342 — attachIterator(new ArrayIterator([...]), …) wires New_ slot, not ctor Array_ prelude. */
    public function testMultipleIteratorAttachInlineNewUsesNewProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$mi = new MultipleIterator(MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC);
$mi->attachIterator(new ArrayIterator(['a' => 1]), 'k1');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'multipleiterator_attach_inline_new.php');

        $newSlots = [];
        $arraySlot = null;
        $attachSends = [];
        $inAttach = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                $inAttach = true;
                $attachSends = [];
            }
            if ($inAttach && OpCode::TYPE_ARG_SEND === $op->type) {
                $attachSends[] = $op->arg1;
            }
            if ($inAttach && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $inAttach = false;
            }
        }

        self::assertGreaterThanOrEqual(2, \count($attachSends), 'attachIterator must emit iterator + info ARG_SEND');
        self::assertContains($attachSends[0], $newSlots, 'attachIterator arg #0 must use New_ slot');
        self::assertNotSame($arraySlot, $attachSends[0], 'attachIterator arg #0 must not use ctor Array_ prelude');
    }

    /** Issue #13342 — MultipleIterator::attachIterator runtime parity with Zend. */
    public function testMultipleIteratorAttachInlineNewRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../compliance/cases/spl/multipleiterator_attach_run.php');
        self::assertIsString($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'multipleiterator_attach_run.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    /** Issue #17524 — DatePeriod inline sibling New_ ctor args wire positional slots, not last New_. */
    public function testDatePeriodInlineSiblingNewUsesPositionalProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), new DateTime('2020-01-03'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dateperiod_inline_sibling_new.php');

        $newSlots = [];
        $periodSends = [];
        $inPeriodCtor = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type && 4 === \count($newSlots)) {
                $inPeriodCtor = true;
            }
            if ($inPeriodCtor && OpCode::TYPE_ARG_SEND === $op->type) {
                $periodSends[] = $op->arg1;
            }
            if ($inPeriodCtor && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertSame([0, 4, 8, 12], $newSlots, 'new slots='.json_encode($newSlots));
        self::assertSame([0, 4, 8], $periodSends, 'DatePeriod arg sends='.json_encode($periodSends));
    }

    /**
     * Issue #19731 — DatePeriod(..., INCLUDE_END_DATE) must not collapse start/end onto the last New_
     * when ClassConstFetch sits between sibling New_ producers and the outer ctor.
     */
    public function testDatePeriodInlineSiblingNewWithIncludeEndDateUsesPositionalProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$p = new DatePeriod(
    new DateTime('2020-01-01'),
    new DateInterval('P1D'),
    new DateTime('2020-01-03'),
    DatePeriod::INCLUDE_END_DATE
);
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dateperiod_inline_include_end.php');

        $newSlots = [];
        $periodSends = [];
        $inPeriodCtor = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type && 4 === \count($newSlots)) {
                $inPeriodCtor = true;
            }
            if ($inPeriodCtor && OpCode::TYPE_ARG_SEND === $op->type) {
                $periodSends[] = $op->arg1;
            }
            if ($inPeriodCtor && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertSame([0, 4, 8, 13], $newSlots, 'new slots='.json_encode($newSlots));
        self::assertSame([0, 4, 8], \array_slice($periodSends, 0, 3), 'DatePeriod New_ arg sends='.json_encode($periodSends));
        self::assertCount(4, $periodSends, 'INCLUDE_END_DATE options arg must still be sent');

        ob_start();
        $runtime->run($block);
        self::assertSame("2020-01-01,2020-01-02,2020-01-03\n", ob_get_clean());
    }

    /**
     * Issue #19735 — EXCLUDE_START_DATE|INCLUDE_END_DATE must not steal arg #0 onto BitwiseOr slot.
     */
    public function testDatePeriodInlineSiblingNewWithBitwiseOrOptionsUsesPositionalProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
$p = new DatePeriod(
    new DateTime('2020-01-01'),
    new DateInterval('P1D'),
    new DateTime('2020-01-04'),
    DatePeriod::EXCLUDE_START_DATE | DatePeriod::INCLUDE_END_DATE
);
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dateperiod_inline_bitwise_options.php');

        $newSlots = [];
        $periodSends = [];
        $inPeriodCtor = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type && 4 === \count($newSlots)) {
                $inPeriodCtor = true;
            }
            if ($inPeriodCtor && OpCode::TYPE_ARG_SEND === $op->type) {
                $periodSends[] = $op->arg1;
            }
            if ($inPeriodCtor && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertSame([0, 4, 8], \array_slice($periodSends, 0, 3), 'DatePeriod New_ arg sends='.json_encode($periodSends));
        self::assertCount(4, $periodSends);
        self::assertNotSame($periodSends[0], $periodSends[3], 'start New_ must not share BitwiseOr options slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("2020-01-02,2020-01-03,2020-01-04\n", ob_get_clean());
    }

    /**
     * Issue #19738 — user multi-arg ctor + trailing Plus/UnaryMinus/Cast must keep positional New_ slots.
     *
     * @dataProvider multiArgCtorTrailingScalarOptionProvider
     */
    public function testMultiArgCtorTrailingScalarOptionUsesPositionalProducerSlots(
        string $flagsExpr,
        int $expectedFlags,
        ?int $optionOpcodeType
    ): void {
        $code = <<<PHP
<?php
class C {
  public function __construct(public \$a, public \$b, public int \$flags) {}
}
\$o = new C(new stdClass(), new stdClass(), {$flagsExpr});
echo get_class(\$o->a), ",", get_class(\$o->b), ",", \$o->flags, "\\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'multi_arg_ctor_trailing_scalar.php');

        $newSlots = [];
        $ctorSends = [];
        $optionSlot = null;
        $inCtor = false;
        foreach ($block->opCodes as $op) {
            if (null !== $optionOpcodeType && $op->type === $optionOpcodeType) {
                $optionSlot = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlots[] = $op->arg1;
                if (3 === \count($newSlots)) {
                    $inCtor = true;
                }
            }
            if ($inCtor && OpCode::TYPE_ARG_SEND === $op->type) {
                $ctorSends[] = $op->arg1;
            }
            if ($inCtor && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(3, $ctorSends, 'ctor sends='.json_encode($ctorSends));
        self::assertSame($newSlots[0], $ctorSends[0], 'arg0 must be first stdClass New_');
        self::assertSame($newSlots[1], $ctorSends[1], 'arg1 must be second stdClass New_');
        self::assertNotSame($ctorSends[0], $ctorSends[2], 'arg0 must not share option slot');
        if (null !== $optionOpcodeType) {
            self::assertNotNull($optionSlot, 'expected trailing option opcode');
            self::assertSame($optionSlot, $ctorSends[2], 'flags must use trailing option slot');
        }

        ob_start();
        $runtime->run($block);
        self::assertSame("stdClass,stdClass,{$expectedFlags}\n", ob_get_clean());
    }

    /** @return iterable<string, array{string, int, ?int}> */
    public static function multiArgCtorTrailingScalarOptionProvider(): iterable
    {
        yield 'plus' => ['1+2', 3, OpCode::TYPE_PLUS];
        yield 'umul' => ['2*3', 6, OpCode::TYPE_MUL];
        yield 'shift' => ['1<<2', 4, OpCode::TYPE_SHIFT_LEFT];
        // -1 folds to a constant slot (no TYPE_UNARY_MINUS opcode) after #13387.
        yield 'uminus' => ['-1', -1, null];
        yield 'cast' => ['(int)1.5', 1, OpCode::TYPE_CAST_INT];
        yield 'bor' => ['1|2', 3, OpCode::TYPE_BITWISE_OR];
    }

    /** Issue #14483 — iterator_count(new DatePeriod(...)) wires outer sibling New_, not inner hoists. */
    public function testIteratorCountInlineDatePeriodUsesSiblingNewProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');
echo iterator_count(new DatePeriod($s, new DateInterval('P1D'), $e)), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dateperiod_inline_iterator_count.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("2\n", ob_get_clean());
    }

    /** Issue #18501 — get_class(new DatePeriod(...)) wires outer sibling New_, not inner DateInterval hoist. */
    public function testGetClassInlineDatePeriodUsesSiblingNewProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');
echo get_class(new DatePeriod($s, new DateInterval('P1D'), $e)), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dateperiod_inline_get_class.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("DatePeriod\n", ob_get_clean());
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

    /** Issue #17210 — var_export(1.0+0.0, true) wires Plus producer, not hoisted true ConstFetch. */
    public function testVarExportInlineArithmeticReturnTrueUsesPlusProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(1.0 + 0.0, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_arithmetic_inline.php');

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

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('1.0', $out);
    }

    /** Issue #17562 — var_export(JSON_HEX_* | …) after echoed json_encode uses BitwiseOr slot, not stale EXEC_RETURN. */
    public function testVarExportBitwiseOrAfterEchoedJsonEncodeUsesArithmeticProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo json_encode('<', JSON_HEX_TAG | JSON_HEX_AMP);
var_export(JSON_HEX_TAG | JSON_HEX_AMP);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_json_hex_after_encode.php');

        $bitwiseOrSlots = [];
        $jsonEncodeExecSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (OpCode::TYPE_BITWISE_OR === $op->type) {
                $bitwiseOrSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && 1 === $fcallOrdinal) {
                $jsonEncodeExecSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $bitwiseOrSlots, 'expected two BitwiseOr preludes');
        self::assertNotNull($jsonEncodeExecSlot);
        self::assertCount(1, $varExportSends);
        self::assertSame($bitwiseOrSlots[1], $varExportSends[0], 'arg sends='.json_encode($varExportSends));
        self::assertNotSame($jsonEncodeExecSlot, $varExportSends[0]);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringEndsWith('3', trim($out));
    }

    /** Issue #26703 — var_export(Fiber::getCurrent() !== null, true) must compile (StaticCall producer). */
    public function testVarExportFiberGetCurrentNotIdenticalNullReturnTrue(): void
    {
        $code = <<<'PHP'
<?php
$f = new Fiber(function () {
    echo var_export(Fiber::getCurrent() !== null, true), "\n";
    echo print_r(Fiber::getCurrent() !== null, true), "\n";
});
$f->start();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_fiber_get_current_cmp.php');

        ob_start();
        $runtime->run($block);
        self::assertSame("true\n1\n", ob_get_clean());
    }

    /** Issue #26702 — var_export(!$object, true) wires BooleanNot result, not the object New_ slot. */
    public function testVarExportUnaryNotObjectReturnTrueUsesBooleanNotSlot(): void
    {
        $code = <<<'PHP'
<?php
$o = new stdClass();
echo var_export(!$o, true), "\n";
$b = !$o;
echo var_export($b, true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_unary_not_object.php');

        $booleanNotSlot = null;
        $newExecSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_BOOLEAN_NOT === $op->type && null === $booleanNotSlot) {
                $booleanNotSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $newExecSlot) {
                $newExecSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($booleanNotSlot);
        self::assertNotNull($newExecSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame(
            $booleanNotSlot,
            $varExportSends[0],
            'arg0 must be BooleanNot result, not New_/object; sends='.json_encode($varExportSends)
        );
        self::assertNotSame($newExecSlot, $varExportSends[0]);

        ob_start();
        $runtime->run($block);
        self::assertSame("false\nfalse\n", ob_get_clean());
    }

    /** Issue #17250 — var_export($x !== false, true) wires NotIdentical producer, not hoisted false ConstFetch. */
    public function testVarExportNotIdenticalFalseReturnTrueUsesComparisonProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(1 !== false, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_not_identical_false.php');

        $notIdenticalSlot = null;
        $constFetchSlots = [];
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $notIdenticalSlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constFetchSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($notIdenticalSlot);
        self::assertCount(2, $constFetchSlots);
        self::assertCount(2, $varExportSends);
        self::assertSame($notIdenticalSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));
        self::assertSame($constFetchSlots[1], $varExportSends[1], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('true', $out);
    }

    /** Issue #17277 — var_export([1] !== false, true) after prior compare must not reuse stale comparison slots. */
    public function testVarExportArrayLiteralNotIdenticalFalseReturnTrueUsesComparisonProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(1 !== false, true);
echo var_export([1] !== false, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_array_not_identical_false.php');

        $notIdenticalSlots = [];
        $varExportSendsByCall = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $notIdenticalSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                $varExportSendsByCall[$fcallOrdinal] = [];
            }
            if (OpCode::TYPE_ARG_SEND === $op->type && isset($varExportSendsByCall[$fcallOrdinal])) {
                $varExportSendsByCall[$fcallOrdinal][] = $op->arg1;
            }
        }

        self::assertCount(2, $notIdenticalSlots);
        self::assertCount(2, $varExportSendsByCall[2] ?? []);
        self::assertSame(
            $notIdenticalSlots[1],
            $varExportSendsByCall[2][0],
            'second var_export arg0 must use its own !== slot'
        );

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('truetrue', str_replace("\n", '', $out));
    }

    /** Issue #17757 — false !== ini_get('bogus') must wire false into NOT_IDENTICAL LHS (#17697). */
    public function testJumpIfNotIdenticalFalseBuiltinCallEmitsConstFetchForFalse(): void
    {
        $code = <<<'PHP'
<?php
if (false !== ini_get('bogus_xyz')) {
    echo "bad\n";
} else {
    echo "ok\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ini_get_not_identical_false.php');

        $notIdenticalLeft = null;
        $notIdenticalRight = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $notIdenticalLeft = $op->arg2;
                $notIdenticalRight = $op->arg3;
            }
        }

        self::assertNotNull($notIdenticalLeft, 'NOT_IDENTICAL must be lowered');
        self::assertNotNull($notIdenticalRight, 'NOT_IDENTICAL must compare builtin return');
        self::assertNotSame($notIdenticalLeft, $notIdenticalRight, 'false and ini_get() must use distinct slots');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("ok\n", $out);
    }

    /** Issue #17259 — static call with two hoisted !== preludes wires distinct comparison slots. */
    public function testStaticCallDualComparisonPreludeArgsUseDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
final class ComparePreludeHelper
{
    public static function extendedArgv(
        string $str,
        string $mask,
        int $offset,
        int $length,
        bool $lenIsNull,
        bool $isStrspn
    ): int {
        return 0;
    }
}

$lenIsNull = 0;
$isStrspn = 1;
ComparePreludeHelper::extendedArgv('a', 'b', 0, 1, 0 !== $lenIsNull, 0 !== $isStrspn);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'static_call_dual_comparison_prelude.php');

        $notIdenticalSlots = [];
        $staticCallSends = [];
        $inStaticCall = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $notIdenticalSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_STATICCALL_INIT === $op->type) {
                $inStaticCall = true;
                $staticCallSends = [];
            }
            if ($inStaticCall && OpCode::TYPE_ARG_SEND === $op->type) {
                $staticCallSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $notIdenticalSlots, 'not identical slots');
        self::assertCount(6, $staticCallSends, 'arg sends='.json_encode($staticCallSends));
        self::assertSame($notIdenticalSlots[0], $staticCallSends[4], 'arg sends='.json_encode($staticCallSends));
        self::assertSame($notIdenticalSlots[1], $staticCallSends[5], 'arg sends='.json_encode($staticCallSends));
    }

    /** Issue #23848 — nested StaticCall arg + trailing ConstFetch must EXEC_RETURN before outer call. */
    public function testNestedStaticCallArgWithLockExPreludeExecReturns(): void
    {
        $code = <<<'PHP'
<?php
class A {
    public static function path(string $x): string { return $x; }
}
function put(string $p, string $d, int $f): void {}
function test(string $id, string $payload): void {
    put(A::path($id), $payload, \LOCK_EX);
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_static_call_lock_ex_arg.php');

        $pathExecSlot = null;
        $firstArgSendSlot = null;
        $awaitingPathExec = false;
        $awaitingOuterSends = false;
        $walk = static function (Block $b) use (&$walk, &$pathExecSlot, &$firstArgSendSlot, &$awaitingPathExec, &$awaitingOuterSends): void {
            foreach ($b->opCodes as $op) {
                if (
                    OpCode::TYPE_STATICCALL_INIT === $op->type
                    && null !== $op->arg2
                    && isset($b->constants[$op->arg2])
                    && 'path' === $b->constants[$op->arg2]->toString()
                ) {
                    $awaitingPathExec = true;
                }
                if ($awaitingPathExec && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                    $pathExecSlot = $op->arg1;
                    $awaitingPathExec = false;
                    $awaitingOuterSends = true;
                }
                if ($awaitingOuterSends && OpCode::TYPE_ARG_SEND === $op->type) {
                    $firstArgSendSlot = $op->arg1;
                    $awaitingOuterSends = false;
                }
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);
        self::assertNotNull($pathExecSlot, 'A::path nested producer must FUNCCALL_EXEC_RETURN');
        self::assertNotNull($firstArgSendSlot, 'outer put() must ARG_SEND path result');
        self::assertSame($pathExecSlot, $firstArgSendSlot);
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

    /** Issue #10070 — var_export(atan2(NAN/INF, …), true) wires nested FuncCall, not hoisted INF/NAN ConstFetch. */
    public function testVarExportAtan2NonFiniteLiteralUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
echo var_export(atan2(INF, INF), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_atan2_non_finite.php');

        $atan2ReturnSlot = null;
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
                $atan2ReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($atan2ReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($atan2ReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('0.7853981633974483', $out);
    }

    /** Issue #5471 / #4633 — var_export(fdiv(...), true) must run nested fdiv before consumer INIT. */
    public function testVarExportInlineFdivNonFiniteUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(fdiv(1.0, 0.0), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_inline_fdiv_inf.php');

        $fdivReturnSlot = null;
        $varExportInitIndex = null;
        $fdivInitIndex = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $i => $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $fdivInitIndex = $i;
                } elseif (2 === $fcallOrdinal) {
                    $varExportInitIndex = $i;
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $fdivReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($fdivReturnSlot);
        self::assertNotNull($fdivInitIndex);
        self::assertNotNull($varExportInitIndex);
        self::assertLessThan($varExportInitIndex, $fdivInitIndex, 'fdiv FUNCCALL_INIT must precede var_export FUNCCALL_INIT');
        self::assertCount(2, $varExportSends);
        self::assertSame($fdivReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('INF', $out);
    }

    /** Issue #10808 — preg_replace() sibling inline Array_ pattern/replacement + embedded subject. */
    public function testPregReplaceDualInlineArrayLiteralsUseBothArraySlots(): void
    {
        $code = <<<'PHP'
<?php
preg_replace(['/a/'], ['A'], 'aba');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'preg_replace_inline_literals.php');

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
        self::assertSame($arraySlots[0], $sendSlots[0], 'pattern array must feed arg #1');
        self::assertSame($arraySlots[1], $sendSlots[1], 'replacement array must feed arg #2');
    }

    /** Issue #10808 — preg_replace() inline array pattern/replacement runtime parity with Zend. */
    public function testPregReplaceInlineArrayPatternReplacementRuntime(): void
    {
        $code = <<<'PHP'
<?php
echo preg_replace(['/a/'], ['A'], 'aba'), "\n";
echo preg_replace(['/a/', '/b/'], ['A', 'B'], 'aba'), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'preg_replace_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("AbA\nABA\n", ob_get_clean());
    }

    /** Issue #9124 — substr_replace() sibling inline Array_ string/replace/offset/length slots. */
    public function testSubstrReplaceMultiInlineArrayLiteralsUseDistinctSendSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
substr_replace(['abcdef', '123'], '.', [2, 1], [2, 1]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'substr_replace_inline_arrays.php');

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
        self::assertCount(4, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[0], $sendSlots[0], 'string array must feed arg #1');
        self::assertSame($arraySlots[1], $sendSlots[2], 'offset array must feed arg #3');
        self::assertSame($arraySlots[2], $sendSlots[3], 'length array must feed arg #4');
    }

    /** Issue #9124 — substr_replace() array $string form runtime parity with Zend. */
    public function testSubstrReplaceArrayFormInlineRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
echo json_encode(substr_replace(['abcdef', '123'], '.', [2, 1], [2, 1])), "\n";
echo json_encode(substr_replace(['abc', 'def'], ['X', 'Y'], 1, 1)), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'substr_replace_array_form_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("[\"ab.ef\",\"1.3\"]\n[\"aXc\",\"dYf\"]\n", ob_get_clean());
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

    /** Issue #16041 — extract([...], EXTR_PREFIX_ALL, Prefix::A) wires Array_ + ConstFetch + ClassConstFetch. */
    public function testExtractInlineArrayFlagsAndEnumPrefixArgSlots(): void
    {
        $code = <<<'PHP'
<?php
enum Prefix { case A; }
extract(['a' => 2], EXTR_PREFIX_ALL, Prefix::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'extract_enum_prefix.php');

        $initArraySlots = [];
        $constFetchSlots = [];
        $classConstSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initArraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type && null !== $op->arg1) {
                $constFetchSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type && null !== $op->arg1) {
                $classConstSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotSame([], $sendSlots, 'expected ARG_SEND opcodes');
        self::assertSame($initArraySlots[0] ?? null, $sendSlots[0] ?? null, 'array arg');
        self::assertSame($constFetchSlots[0] ?? null, $sendSlots[1] ?? null, 'flags arg');
        self::assertSame($classConstSlots[0] ?? null, $sendSlots[2] ?? null, 'prefix arg');
    }

    /** Issue #16041 — extract enum prefix throws TypeError on argument #3 at runtime. */
    public function testExtractInlineEnumPrefixRuntimeTypeError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_extract_enum_prefix.php');
        self::assertIsString($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'extract_enum_prefix.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "TypeError: extract(): Argument #3 (\$prefix) must be of type string, Prefix given\n",
            ob_get_clean()
        );
    }

    /** Issue #8883 — array_pad([E::A], N, E::B) wires inline enum haystack + pad-value ConstFetch. */
    public function testArrayPadInlineEnumHaystackAndPadValueRuntime(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; case B = 2; }
var_export(array_pad([E::A], 3, E::B));
echo "\n";
foreach (array_pad([E::A], 3, E::B) as $v) {
    echo get_debug_type($v), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_pad_inline_enum.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  0 => \n  \\E::A,\n  1 => \n  \\E::B,\n  2 => \n  \\E::B,\n)\nE\nE\nE\n",
            ob_get_clean()
        );
    }

    /** Issue #16560 — array_pad([1], Len::Two, 0) must wire enum length to arg #2, not pad value. */
    public function testArrayPadEnumLengthArgNotMisboundToPadValue(): void
    {
        $code = <<<'PHP'
<?php
enum Len: int { case Two = 2; }
try {
    array_pad([1], Len::Two, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_pad_enum_length.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array_pad(): Argument #2 (\$length) must be of type int, Len given\n",
            ob_get_clean()
        );
    }

    /** Issue #9971 / #16560 — array_chunk([1,2,3], Len::Two) wires haystack Array_ + length ClassConstFetch. */
    public function testArrayChunkInlineArrayEnumLengthTypeErrorRuntime(): void
    {
        $code = <<<'PHP'
<?php
enum Len: int { case Two = 2; }
try {
    array_chunk([1, 2, 3], Len::Two);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_chunk_enum_length.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array_chunk(): Argument #2 (\$length) must be of type int, Len given\n",
            ob_get_clean()
        );
    }

    /** Issue #16316 / #8886 — inline [int, enum] haystack must keep scalar before enum case. */
    public function testArraySearchStrictMixedHaystackInlineLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_export(array_search(1, [1, E::A], true));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_search_strict_scalar_order.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("0\n", ob_get_clean());
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

        self::assertGreaterThanOrEqual(3, \count($arraySlots), 'array inits='.json_encode($arraySlots));
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

    /** Issue #17872 — define(array); var_export(CONST) wires ConstFetch slot, not define() bool return. */
    public function testVarExportAfterDefineArrayUsesConstFetchSlot(): void
    {
        $code = <<<'PHP'
<?php
define('ARR', [1, 2]);
var_export(ARR);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'define_array_var_export.php');

        $constFetchSlot = null;
        $defineReturnSlot = null;
        $varExportSendSlot = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constFetchSlot) {
                $constFetchSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $defineReturnSlot) {
                $defineReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $varExportSendSlot) {
                $varExportSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($constFetchSlot, 'expected CONST_FETCH slot for ARR');
        self::assertNotNull($varExportSendSlot, 'expected var_export ARG_SEND');
        self::assertSame($constFetchSlot, $varExportSendSlot);
        if (null !== $defineReturnSlot) {
            self::assertNotSame($defineReturnSlot, $varExportSendSlot);
        }
    }

    /** Issue #17872 — define(array); var_export(CONST) runtime parity with Zend. */
    public function testVarExportAfterDefineArrayRuntime(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_define_array_constant.php');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_define_array_constant.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('0 => 1', $out);
        self::assertStringContainsString('1 => 2', $out);
        self::assertStringNotContainsString('true', $out);
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

    /** Issue #10203 — var_dump(E::A <=> E::B) must send spaceship int, not hoisted enum case (#9575). */
    public function testVarDumpEnumSpaceshipSendsSpaceshipResultSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
enum E: int { case A = 1; case B = 2; }
var_dump(E::A <=> E::B);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_spaceship_var_dump.php');

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

    /** Issue #13945 — hoisted null operand before spaceship must not bind ConstFetch slot to var_dump arg. */
    public function testVarDumpNullSpaceshipSendsSpaceshipResultSlot(): void
    {
        $code = <<<'PHP'
<?php
var_dump(null <=> 1);
var_dump(null < 1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'null_spaceship_var_dump.php');

        $compareSlots = [];
        $argSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_SPACESHIP === $op->type || OpCode::TYPE_SMALLER === $op->type) {
                $compareSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $argSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $compareSlots, 'spaceship + relational compare must lower');
        self::assertCount(2, $argSends, 'two var_dump arg sends expected');
        self::assertSame($compareSlots, $argSends, 'inline compare results must feed var_dump args');
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

    /** Issue #12082 — var_export($u[0] === $u[1]) wires Identical producer, not trailing ArrayDimFetch. */
    public function testVarExportArrayDimIdenticalUsesIdenticalProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$o = new stdClass();
$a = [$o, $o];
$u = unserialize(serialize($a));
var_export($u[0] === $u[1]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_dim_identical_var_export.php');

        $identSlot = null;
        $dimFetchSlots = [];
        $sendSlots = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_IDENTICAL === $op->type) {
                $identSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                $dimFetchSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $sendSlots = [];
                }
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($identSlot);
        self::assertCount(2, $dimFetchSlots);
        self::assertCount(1, $sendSlots);
        self::assertSame($identSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($dimFetchSlots[1], $sendSlots[0], 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('true', $out);
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

    /** Issue #16096 / re-#10909 — in_array(null, [null], true) wires inline haystack + literal strict slots. */
    public function testInArrayNullNeedleInlineHaystackStrictUsesArrayAndBoolSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
var_export(in_array(null, [null], true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_null_inline_strict.php');

        $arraySlot = null;
        $inArraySends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
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

        self::assertNotNull($arraySlot);
        self::assertCount(3, $inArraySends, 'in_array arg sends='.json_encode($inArraySends));
        self::assertSame($arraySlot, $inArraySends[1], 'inline haystack slot');
        self::assertNotSame($inArraySends[0], $inArraySends[1], 'needle and haystack must differ');
        self::assertNotSame($inArraySends[1], $inArraySends[2], 'haystack and strict must differ');
        self::assertNotSame($inArraySends[0], $inArraySends[2], 'needle and strict must differ');
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

    /**
     * Issue #19439 — new Outer(new Inner(..., Class::CONST), …) must send inner New_ slot, not ClassConstFetch.
     */
    public function testNestedNewConstructorClassConstInnerArgUsesInnerProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class Inner {
    public $a;
    public $b;
    public function __construct($a, $b = null) { $this->a = $a; $this->b = $b; }
}
class Outer {
    public $x;
    public $y;
    public function __construct($x, $y = null) { $this->x = $x; $this->y = $y; }
}
$o = new Outer(new Inner(1, FilesystemIterator::SKIP_DOTS), 3);
echo is_object($o->x) ? "obj:{$o->x->a}:{$o->x->b}:{$o->y}" : gettype($o->x);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_new_class_const_inner.php');

        $innerNewSlot = null;
        $outerArgSends = [];
        $seenOuterNew = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $classSlot = $op->arg2;
                if (null !== $classSlot && isset($block->constants[$classSlot])) {
                    $className = $block->constants[$classSlot]->toString();
                    if ('Inner' === $className) {
                        $innerNewSlot = $op->arg1;
                    }
                    if ('Outer' === $className) {
                        $seenOuterNew = true;
                        $outerArgSends = [];
                    }
                }
            }
            if ($seenOuterNew && OpCode::TYPE_ARG_SEND === $op->type) {
                $outerArgSends[] = $op->arg1;
            }
            if ($seenOuterNew && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertNotNull($innerNewSlot, 'Inner New_ slot');
        self::assertCount(2, $outerArgSends, 'Outer ctor arg sends='.json_encode($outerArgSends));
        self::assertSame($innerNewSlot, $outerArgSends[0], 'Outer arg #0 must be Inner New_ slot, not ClassConstFetch');
        self::assertNotSame($innerNewSlot, $outerArgSends[1]);

        ob_start();
        $runtime->run($block);
        self::assertSame('obj:1:4096:3', ob_get_clean());
    }

    /** Issue #21957 — take2('x', new FilesystemIterator($dir, SKIP_DOTS)) must receive the object. */
    public function testMultiArgNewAsSecondCallArgUsesNewResultNotFlagsConst(): void
    {
        $code = <<<'PHP'
<?php
$dir = sys_get_temp_dir() . '/phpc_fsit_unit_21957';
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/a.txt', 'x');
function take2($label, $o) {
    echo $label, '=', get_debug_type($o);
    if (!is_object($o)) {
        echo ' value=', var_export($o, true);
    }
    echo "\n";
}
take2('inline_two', new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'fsit_multiarg_new_call_arg.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("inline_two=FilesystemIterator\n", ob_get_clean());
    }

    /** Issue #22062 — new FilesystemIterator(__DIR__, SKIP_DOTS) must pass directory path as arg #0. */
    public function testFilesystemIteratorDirMagicConstWithFlagsCtorArgs(): void
    {
        $dir = sys_get_temp_dir() . '/phpc_fsit_unit_22062';
        $code = <<<PHP
<?php
\$dir = '{$dir}';
if (!is_dir(\$dir)) {
    mkdir(\$dir);
}
file_put_contents(\$dir . '/a.txt', 'x');
\$f = new FilesystemIterator(__DIR__, FilesystemIterator::SKIP_DOTS);
echo 'path=', \$f->getPath(), ' flags=', \$f->getFlags(), "\\n";
\$f2 = new FilesystemIterator(\$dir, FilesystemIterator::SKIP_DOTS);
echo 'varpath=', \$f2->getPath(), "\\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'fsit_dir_magic_flags.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('flags=4096', $out);
        self::assertStringNotContainsString('Failed to open directory', $out);
        self::assertMatchesRegularExpression('/path=.+/', $out);
        self::assertStringContainsString('varpath='.$dir, $out);
    }

    /**
     * Issue #19769 — new CachingIterator(new ArrayIterator(...), CachingIterator::FULL_CACHE)
     * must send New_ for arg0 and FULL_CACHE int for arg1 (not double-send New_).
     */
    public function testCachingIteratorNestedNewFullCacheClassConstArgSlots(): void
    {
        $code = <<<'PHP'
<?php
$it = new CachingIterator(new ArrayIterator(['a', 'b']), CachingIterator::FULL_CACHE);
foreach ($it as $v) {
    echo "v=$v\n";
}
echo "ok\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'cachingiterator_nested_full_cache.php');

        $arrayIteratorSlot = null;
        $cachingSends = [];
        $inCaching = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $classSlot = $op->arg2;
                if (null !== $classSlot && isset($block->constants[$classSlot])) {
                    $className = $block->constants[$classSlot]->toString();
                    if ('ArrayIterator' === $className) {
                        $arrayIteratorSlot = $op->arg1;
                    }
                    if ('CachingIterator' === $className) {
                        $inCaching = true;
                        $cachingSends = [];
                    }
                }
            }
            if ($inCaching && OpCode::TYPE_ARG_SEND === $op->type) {
                $cachingSends[] = $op->arg1;
            }
            if ($inCaching && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertNotNull($arrayIteratorSlot, 'ArrayIterator New_ slot');
        self::assertCount(2, $cachingSends, 'CachingIterator sends='.json_encode($cachingSends));
        self::assertSame($arrayIteratorSlot, $cachingSends[0], 'arg0 must be ArrayIterator New_');
        self::assertNotSame($arrayIteratorSlot, $cachingSends[1], 'arg1 must not double-send New_ (#19769)');
        self::assertTrue(
            isset($block->constants[$cachingSends[1]]),
            'arg1 should be folded FULL_CACHE constant slot'
        );
        self::assertSame(256, (int) $block->constants[$cachingSends[1]]->toInt());

        ob_start();
        $runtime->run($block);
        self::assertSame("v=a\nv=b\nok\n", ob_get_clean());
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

    /**
     * Issue #19770 — new LimitIterator(new InfiniteIterator(new ArrayIterator([...])), 0, n)
     * must send InfiniteIterator New_ (not innermost ArrayIterator) to LimitIterator arg0.
     */
    public function testLimitIteratorInfiniteIteratorNestedNewArgSlots(): void
    {
        $code = <<<'PHP'
<?php
$it = new LimitIterator(new InfiniteIterator(new ArrayIterator([7, 8])), 0, 5);
echo implode(',', iterator_to_array($it, false));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'limititerator_infinite_nested.php');

        $arrayIteratorSlot = null;
        $infiniteIteratorSlot = null;
        $limitSends = [];
        $inLimit = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $classSlot = $op->arg2;
                if (null !== $classSlot && isset($block->constants[$classSlot])) {
                    $className = $block->constants[$classSlot]->toString();
                    if ('ArrayIterator' === $className) {
                        $arrayIteratorSlot = $op->arg1;
                    }
                    if ('InfiniteIterator' === $className) {
                        $infiniteIteratorSlot = $op->arg1;
                    }
                    if ('LimitIterator' === $className) {
                        $inLimit = true;
                        $limitSends = [];
                    }
                }
            }
            if ($inLimit && OpCode::TYPE_ARG_SEND === $op->type) {
                $limitSends[] = $op->arg1;
            }
            if ($inLimit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertNotNull($arrayIteratorSlot, 'ArrayIterator New_ slot');
        self::assertNotNull($infiniteIteratorSlot, 'InfiniteIterator New_ slot');
        self::assertCount(3, $limitSends, 'LimitIterator sends='.json_encode($limitSends));
        self::assertSame($infiniteIteratorSlot, $limitSends[0], 'arg0 must be InfiniteIterator New_ (#19770)');
        self::assertNotSame($arrayIteratorSlot, $limitSends[0], 'arg0 must not be innermost ArrayIterator');

        ob_start();
        $runtime->run($block);
        self::assertSame('7,8,7,8,7', ob_get_clean());
    }

    /**
     * Issue #19771 — new CallbackFilterIterator(new ArrayIterator([...]), fn() => …)
     * must send Closure slot for arg #1, not the inner New_ object.
     */
    public function testNestedNewConstructorInlineArrowCallbackUsesClosureSlot(): void
    {
        $code = <<<'PHP'
<?php
$it = new CallbackFilterIterator(new ArrayIterator([1, 2, 3, 4]), fn($v) => $v % 2 === 0);
echo implode(',', iterator_to_array($it, false));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'callback_filter_nested_arrow.php');

        $innerNewSlot = null;
        $closureSlot = null;
        $outerArgSends = [];
        $seenOuterNew = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $closureSlot = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type) {
                $classSlot = $op->arg2;
                if (null !== $classSlot && isset($block->constants[$classSlot])) {
                    $className = $block->constants[$classSlot]->toString();
                    if ('ArrayIterator' === $className) {
                        $innerNewSlot = $op->arg1;
                    }
                    if ('CallbackFilterIterator' === $className) {
                        $seenOuterNew = true;
                        $outerArgSends = [];
                    }
                }
            }
            if ($seenOuterNew && OpCode::TYPE_ARG_SEND === $op->type) {
                $outerArgSends[] = $op->arg1;
            }
            if ($seenOuterNew && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertNotNull($innerNewSlot, 'ArrayIterator New_ slot');
        self::assertNotNull($closureSlot, 'ArrowFunction CLOSURE slot');
        self::assertCount(2, $outerArgSends, 'CallbackFilterIterator ctor arg sends='.json_encode($outerArgSends));
        self::assertSame($innerNewSlot, $outerArgSends[0], 'arg #0 must be ArrayIterator New_ slot');
        self::assertSame($closureSlot, $outerArgSends[1], 'arg #1 must be Closure slot, not inner New_');

        ob_start();
        $runtime->run($block);
        self::assertSame('2,4', ob_get_clean());
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

    /** Issue #22770 — new C(null, [...]) must send null ConstFetch slot, not sibling Array_. */
    public function testNewNullLiteralThenArrayUsesDistinctProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function __construct($a, $b) {}
}
new C(null, ['k' => 1]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'new_null_literal_array_arg_slots.php');

        $constFetchSlots = [];
        $arraySlots = [];
        $ctorSendSlots = [];
        $seenNew = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constFetchSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type) {
                $seenNew = true;
                $ctorSendSlots = [];
                continue;
            }
            if ($seenNew && OpCode::TYPE_ARG_SEND === $op->type) {
                $ctorSendSlots[] = $op->arg1;
            }
            if ($seenNew && (
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type
                || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
            )) {
                break;
            }
        }

        self::assertCount(2, $ctorSendSlots, 'ctor sends='.json_encode($ctorSendSlots));
        self::assertNotEmpty($arraySlots);
        self::assertSame($arraySlots[0], $ctorSendSlots[1], 'arg1 must use Array_ slot');
        self::assertNotSame(
            $arraySlots[0],
            $ctorSendSlots[0],
            'arg0 must not reuse Array_ slot (null literal / ConstFetch)'
        );
        self::assertNotSame($ctorSendSlots[0], $ctorSendSlots[1]);
    }

    /** Issue #22770 — new C(null, [...]) runtime parity with Zend. */
    public function testNewNullLiteralThenArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function __construct($a, $b) {
        echo 'a=', var_export($a, true), ' b=', gettype($b), "\n";
    }
}
new C(null, ['k' => 1]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'new_null_literal_array_arg_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("a=NULL b=array\n", ob_get_clean());
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

    /** Issue #11272 — var_export(array_keys($a, null), true) must not wire inner null literal to arg #0. */
    public function testVarExportArrayKeysNullSearchLiteralUsesNestedReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$a = [null => 1];
echo var_export(array_keys($a, null), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_array_keys_null_search.php');

        $keysReturnSlot = null;
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
                $keysReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($keysReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($keysReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        self::assertSame('array (
)', ob_get_clean());
    }

    /** Issue #11896 / #13810 — var_export(C::__set_state([]), true) wires StaticCall producer, not dead arg temp. */
    public function testVarExportSetStateInlineStaticCallReturnTrueUsesStaticCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
class VE {
    public static function __set_state(array $a): self { return new self(); }
}
echo var_export(VE::__set_state([]), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_set_state_inline.php');

        $staticReturnSlot = null;
        $varExportSends = [];
        $pendingStaticReturn = false;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_STATICCALL_INIT === $op->type) {
                $pendingStaticReturn = true;
            }
            if ($pendingStaticReturn && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $staticReturnSlot = $op->arg1;
                $pendingStaticReturn = false;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($staticReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($staticReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('VE::__set_state(array', $out);
        self::assertStringNotContainsString('NULL', $out);
    }

    /** Issue #10733 — var_export([bool, $dt->format(...)]) wires Array_ producer, not hoisted MethodCall element. */
    public function testVarExportInlineArrayWithMethodCallElementUsesArrayProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$dt = new DateTime('2020-01-01');
var_export([true, $dt->format('Y-m-d')]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_inline_array_method_element.php');

        $initArraySlot = null;
        $methodReturnSlot = null;
        $varExportSendSlot = null;
        $pendingMethod = false;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                $pendingMethod = true;
            }
            if ($pendingMethod && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $methodReturnSlot = $op->arg1;
                $pendingMethod = false;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($initArraySlot);
        self::assertNotNull($methodReturnSlot);
        self::assertNotNull($varExportSendSlot);
        self::assertSame($initArraySlot, $varExportSendSlot, 'var_export must send array slot, not method return');
        self::assertNotSame($methodReturnSlot, $varExportSendSlot);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('array (', $out);
        self::assertStringContainsString("'2020-01-01'", $out);
    }

    /** Issue #15783 — var_export([0, strlen('x')]) wires Array_ producer, not hoisted strlen element. */
    public function testVarExportInlineArrayWithFuncCallElementUsesArrayProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
var_export([0, strlen('x')]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_inline_array_func_element.php');

        $initArraySlot = null;
        $strlenReturnSlot = null;
        $varExportSendSlot = null;
        $pendingStrlen = false;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $pendingStrlen = true;
                }
            }
            if ($pendingStrlen && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $strlenReturnSlot = $op->arg1;
                $pendingStrlen = false;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($initArraySlot);
        self::assertNotNull($strlenReturnSlot);
        self::assertNotNull($varExportSendSlot);
        self::assertSame($initArraySlot, $varExportSendSlot, 'var_export must send array slot, not strlen return');
        self::assertNotSame($strlenReturnSlot, $varExportSendSlot);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('array (', $out);
        self::assertStringContainsString('1', $out);
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
        self::assertNotSame($intersectSends[0], $intersectSends[1], 'intersect sends='.json_encode($intersectSends));
        self::assertContains($intersectSends[0], $keysReturnSlots, 'fcall returns='.json_encode($keysReturnSlots));
        self::assertContains($intersectSends[1], $keysReturnSlots, 'fcall returns='.json_encode($keysReturnSlots));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("ok", $out);
    }

    /** Issue #16056 — literal Array_ before array_keys($var) feeds Identical, not array_keys arg #0. */
    public function testArrayKeysNamedVarNotWiredToPrecedingIdenticalLiteral(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$a = ['a10' => 1, 'a2' => 2];
uksort($a, 'strnatcmp');
var_export(['a2', 'a10'] === array_keys($a));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_keys_identical_literal.php');

        $keysSendSlot = null;
        $keysReturnSlot = null;
        $identicalLeftSlot = null;
        $identicalRightSlot = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $keysSendSlot) {
                $keysSendSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $keysReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_IDENTICAL === $op->type) {
                $identicalLeftSlot = $op->arg2;
                $identicalRightSlot = $op->arg3;
            }
        }

        self::assertNotNull($keysSendSlot, 'array_keys arg #0 must be sent');
        self::assertNotNull($keysReturnSlot, 'array_keys return slot must exist');
        self::assertSame($keysReturnSlot, $identicalRightSlot, 'array_keys return feeds Identical right');
        self::assertNotSame($keysSendSlot, $identicalLeftSlot, 'array_keys must not receive Identical literal');
        self::assertNotSame($keysSendSlot, $identicalRightSlot, 'array_keys haystack slot must differ from return');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("true\n", $out);
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

    /** Issue #21981 — array_keys(array_flip([...])) ARG_SEND uses nested FuncCall EXEC_RETURN, not INIT_ARRAY. */
    public function testArrayKeysNestedArrayFlipProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_keys(array_flip(['a', 'b'])));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_keys_nested_flip.php');

        $flipReturnSlot = null;
        $keysSendSlot = null;
        $initArraySlot = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $initArraySlot) {
                $initArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $flipReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $keysSendSlot) {
                $keysSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($flipReturnSlot, 'array_flip return slot');
        self::assertNotNull($keysSendSlot, 'array_keys ARG_SEND slot');
        self::assertNotNull($initArraySlot, 'INIT_ARRAY slot');
        self::assertSame($flipReturnSlot, $keysSendSlot, 'array_keys must receive flip EXEC_RETURN');
        self::assertNotSame($initArraySlot, $keysSendSlot, 'array_keys must not steal nested INIT_ARRAY');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 'a'", $out);
        self::assertStringContainsString("1 => 'b'", $out);
    }

    /** Issue #21981 — maintainer_gap nested producers match Zend (no TypeError). */
    public function testArrayKeysNestedCallNullMaintainerGapRepro(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_keys_nested_call_null.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_keys_nested_call_null.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 'a'", $out);
        self::assertStringContainsString("0 => 'b'", $out);
        self::assertStringContainsString("0 => 'k'", $out);
        self::assertStringNotContainsString('TypeError', $out);
    }

    /** Issue #25812 — array_keys($ao->getArrayCopy()) ARG_SEND uses MethodCall EXEC_RETURN, not INIT_ARRAY. */
    public function testArrayKeysNestedGetArrayCopyProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = new ArrayObject(['b' => 2, 'a' => 1]);
var_export(array_keys($a->getArrayCopy()));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_keys_nested_getarraycopy.php');

        $copyReturnSlot = null;
        $keysSendSlot = null;
        $initArraySlot = null;
        $methodOrdinal = 0;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $initArraySlot) {
                $initArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                ++$methodOrdinal;
            }
            if (1 === $methodOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $copyReturnSlot) {
                $copyReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $keysSendSlot) {
                $keysSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($copyReturnSlot, 'getArrayCopy return slot');
        self::assertNotNull($keysSendSlot, 'array_keys ARG_SEND slot');
        self::assertNotNull($initArraySlot, 'INIT_ARRAY slot');
        self::assertSame($copyReturnSlot, $keysSendSlot, 'array_keys must receive getArrayCopy EXEC_RETURN');
        self::assertNotSame($initArraySlot, $keysSendSlot, 'array_keys must not steal constructor INIT_ARRAY');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 'b'", $out);
        self::assertStringContainsString("1 => 'a'", $out);
        self::assertStringNotContainsString('TypeError', $out);
    }

    /** Issue #25812 — ArrayObject/ArrayIterator nested getArrayCopy match Zend. */
    public function testArrayKeysNestedGetArrayCopyIssueRepro(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25812_array_keys_getarraycopy.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_25812_array_keys_getarraycopy.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("0 => 'b'", $out);
        self::assertStringContainsString("1 => 'a'", $out);
        self::assertStringContainsString("0 => 2", $out);
        self::assertStringContainsString("\n2\n", $out);
        self::assertStringNotContainsString('TypeError', $out);
    }

    /**
     * Issue #26770 — after echo implode(',', array_keys($o->m())), array_keys(get_object_vars($o))
     * must ARG_SEND the get_object_vars EXEC_RETURN, not the prior implode string slot.
     */
    public function testArrayKeysGetObjectVarsAfterMethodCallEchoChain(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue-26770-get-object-vars-nested-echo.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue-26770-get-object-vars-nested-echo.php');

        $getObjectVarsReturn = null;
        $arrayKeysSendAfterGov = null;
        $seenGovInit = false;
        $pendingArrayKeys = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $name = $block->constants[$op->arg1]->toString();
                if ('get_object_vars' === $name) {
                    $seenGovInit = true;
                    $pendingArrayKeys = false;
                } elseif ('array_keys' === $name && $seenGovInit && null === $arrayKeysSendAfterGov) {
                    $pendingArrayKeys = true;
                } else {
                    $pendingArrayKeys = false;
                }
            }
            if ($seenGovInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $getObjectVarsReturn) {
                $getObjectVarsReturn = $op->arg1;
            }
            if ($pendingArrayKeys && OpCode::TYPE_ARG_SEND === $op->type && null === $arrayKeysSendAfterGov) {
                $arrayKeysSendAfterGov = $op->arg1;
                $pendingArrayKeys = false;
            }
        }

        self::assertNotNull($getObjectVarsReturn, 'get_object_vars EXEC_RETURN');
        self::assertNotNull($arrayKeysSendAfterGov, 'array_keys ARG_SEND after get_object_vars');
        self::assertSame(
            $getObjectVarsReturn,
            $arrayKeysSendAfterGov,
            'array_keys must receive get_object_vars EXEC_RETURN, not prior implode'
        );

        ob_start();
        $runtime->run($block);
        self::assertSame("B::vars keys=b,c,d\nglobal keys=c\n", ob_get_clean());
    }

    /** Issue #15558 — maintainer_gap repro: assignment form matches var_export probe (#13776). */
    public function testArrayCombineInlineArrayKeysMaintainerGapRepro(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_combine_inline_array_keys.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_array_combine_inline_array_keys.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('ok', $out);
    }

    /** Issue #10214 — array_combine([1, 2], [3]) sibling inline Array_ literals use distinct producer slots. */
    public function testArrayCombineSiblingInlineLiteralLengthMismatchRuntime(): void
    {
        $code = <<<'PHP'
<?php
array_combine([1, 2], [3]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_combine_inline_literal_mismatch.php');

        $combineSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $combineSends[] = $op->arg1;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(2, $combineSends);
        self::assertNotSame($combineSends[0], $combineSends[1], 'combine sends='.json_encode($combineSends));
    }

    /** Issue #17981 — array_combine($keys ?? [], $values ?? []) maps each stmt-level ?? to its arg slot. */
    public function testArrayCombineCoalesceWrappedInlineCallArgsUseDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
function combine_keys_values(array $keys, array $values): void {
    $c = array_combine($keys ?? [], $values ?? []);
    echo $c['a'], '|', $c['b'], "\n";
}
combine_keys_values(['a', 'b'], [1, 2]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_combine_coalesce_call_arg.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("1|2\n", $out);
    }

    /** Issue #15874 — array_walk((object)[...], fn) wires hoisted Cast to by-ref arg #0. */
    public function testArrayWalkObjectCastInlineCallArgZeroSlot(): void
    {
        $code = <<<'PHP'
<?php
array_walk((object) ['x' => 1], static fn ($v) => $v);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_walk_object_cast.php');

        $castSlot = null;
        $walkSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_OBJECT === $op->type) {
                $castSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $walkSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $walkSends[] = $op->arg1;
            }
        }

        self::assertNotNull($castSlot);
        self::assertCount(2, $walkSends);
        self::assertSame($castSlot, $walkSends[0], 'walk sends='.json_encode($walkSends));
        self::assertNotSame($walkSends[0], $walkSends[1], 'walk sends='.json_encode($walkSends));
    }

    /**
     * Issue #22894 — stmt-level (string)$obj must not steal ARG_SEND for a later $obj($arg) invoke.
     * (Hoisted Cast→arg0 is only for allowlisted array_* inline casts — #15858.)
     */
    public function testStmtStringCastDoesNotStealInvokeArgSend(): void
    {
        $code = <<<'PHP'
<?php
class M {
    public function __toString() { return 'M'; }
    public function __invoke($x) { return $x; }
}
$m = new M;
(string) $m;
$m(21);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'tostring_then_invoke_arg.php');

        $castSlot = null;
        $invokeArgSlot = null;
        $seenCast = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_STRING === $op->type) {
                $castSlot = $op->arg1;
                $seenCast = true;
                continue;
            }
            if (!$seenCast) {
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type && null === $invokeArgSlot) {
                $invokeArgSlot = $op->arg1;
                break;
            }
        }

        self::assertNotNull($castSlot);
        self::assertNotNull($invokeArgSlot);
        self::assertNotSame(
            $castSlot,
            $invokeArgSlot,
            'invoke ARG_SEND must not reuse stmt (string) cast slot'
        );
        self::assertTrue(
            isset($block->constants[$invokeArgSlot]),
            'invoke arg should be literal 21 constant slot'
        );
        self::assertSame(21, $block->constants[$invokeArgSlot]->toInt());
    }

    /** Issue #15858 — array_merge((object)[...], [...]) wires hoisted Cast to arg #0, not trailing Array_. */
    public function testArrayMergeObjectCastInlineCallArgZeroSlot(): void
    {
        $code = <<<'PHP'
<?php
array_merge((object) ['a' => 1], ['b' => 2]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_merge_object_cast.php');

        $castSlot = null;
        $mergeSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_OBJECT === $op->type) {
                $castSlot = $op->arg1;
            }
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

        self::assertNotNull($castSlot);
        self::assertCount(2, $mergeSends);
        self::assertSame($castSlot, $mergeSends[0], 'merge sends='.json_encode($mergeSends));
        self::assertNotSame($mergeSends[0], $mergeSends[1], 'merge sends='.json_encode($mergeSends));
    }

    /**
     * Issue #28822 — array_keys((array)$ao) must ARG_SEND the Cast result, not ctor INIT_ARRAY.
     * rewireArrayKeysInlineInitArrayArgSendSlots must not undo Cast→arg0 (#15858 allowlist).
     */
    public function testArrayKeysArrayObjectCastInlineCallArgZeroSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a->asort();
array_keys((array) $a);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_keys_arrayobject_cast.php');

        $castSlot = null;
        $keysSend = null;
        $inKeys = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CAST_ARRAY === $op->type) {
                $castSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $name = $block->constants[$op->arg1] ?? null;
                $inKeys = null !== $name
                    && 'array_keys' === strtolower((string) $name->toString());
            }
            if ($inKeys && OpCode::TYPE_ARG_SEND === $op->type && null === $keysSend) {
                $keysSend = $op->arg1;
                $inKeys = false;
            }
        }

        self::assertNotNull($castSlot, 'expected TYPE_CAST_ARRAY');
        self::assertNotNull($keysSend, 'expected array_keys ARG_SEND');
        self::assertSame(
            $castSlot,
            $keysSend,
            'array_keys ARG_SEND must be Cast slot, not ctor INIT_ARRAY'
        );
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

    /** Issue #15421 — array_pad negative literal after UDF array param must not mis-bind length to haystack. */
    public function testArrayPadNegativeLiteralAfterUdfArrayParamUsesDistinctArgSlots(): void
    {
        $code = <<<'PHP'
<?php
function hold(array $v): void
{
}
hold([]);
$r = array_pad([1, 2], -4, 0);
var_export($r);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_pad_after_udf_array.php');

        $padSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $padSends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $padSends[] = $op->arg1;
            }
        }

        self::assertCount(3, $padSends);
        self::assertNotSame($padSends[0], $padSends[1], 'arg sends='.json_encode($padSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('0', $out);
        self::assertStringContainsString('1', $out);
        self::assertStringContainsString('2', $out);
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

    /** Issue #15438 — var_export(sys_getloadavg(), true) wires zero-arg array-return FuncCall producer. */
    public function testVarExportNestedZeroArgArrayBuiltinUsesFuncCallProducerSlot(): void
    {
        if (!\function_exists('sys_getloadavg') && !\is_readable('/proc/loadavg')) {
            self::markTestSkipped('sys_getloadavg and /proc/loadavg unavailable');
        }

        $code = <<<'PHP'
<?php
echo var_export(sys_getloadavg(), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_sys_getloadavg.php');

        $loadavgReturnSlot = null;
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
                $loadavgReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($loadavgReturnSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($loadavgReturnSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('array (', $out);
        self::assertStringNotContainsString('NULL', $out);
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

    /** Issue #25097 — cast stdClass: by-ref next() must not GC properties before current()/var_export. */
    public function testVarExportCurrentAfterNextOnCastStdClass(): void
    {
        $code = <<<'PHP'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$o2 = (object)['a' => 1, 'b' => 2];
next($o2);
echo 'next_current=' . var_export(current($o2), true) . ' key=' . var_export(key($o2), true) . "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_current_cast_stdclass.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("next_current=2 key='b'\n", $out);
    }

    /** Issue #16272 — var_export($nested, true) after chown assign must not wire sibling chown EXEC_RETURN as return arg. */
    public function testVarExportAfterChownAssignUsesConstFetchTrueSlot(): void
    {
        $code = <<<'PHP'
<?php
$path = '/nope/' . getmypid();
$nested = chown($path, getmyuid());
echo 'nested: ' . var_export($nested, true) . "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_after_chown_assign.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("nested: false\n", $out);
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

    /** Issue #18183 — consecutive echo var_export($g->current(), true) after bare-yield send (Zend/zend_generators.c). */
    public function testVarExportNestedGeneratorCurrentDoubleEchoAfterBareYieldSend(): void
    {
        $code = <<<'PHP'
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
$g->rewind();
$g->send(3);
echo var_export($g->current(), true), "\n";
echo var_export($g->current(), true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_generator_current_double_echo.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("6\n6\n", $out);
    }

    /** var_export($g->valid(), true) after Generator::send assign + prior var_export (Zend/zend_generators.c). */
    public function testVarExportNestedGeneratorValidAfterSendAssignUsesMethodCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$g = (function (): Generator { yield 1; })();
$g->next();
$r = $g->send(99);
echo 'send=', var_export($r, true), "\n";
echo 'valid_inline=', var_export($g->valid(), true), "\n";
$v = $g->valid();
echo 'valid_stored=', var_export($v, true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_nested_generator_valid_after_send.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("send=NULL\nvalid_inline=false\nvalid_stored=false\n", $out);
    }

    /** Issue #18184 — var_export($g2->current(), true) after prior bare-yield send on another generator. */
    public function testVarExportSecondGeneratorCurrentAfterFirstSendUsesCorrectMethodCallExecReturn(): void
    {
        $code = <<<'PHP'
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
$g->send(3);
$g2 = g();
$g2->rewind();
$g2->send(3);
echo var_export($g2->current(), true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_generator_cross_instance_current.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("6\n", $out);
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

    /** Issue #17572 — substr(dechex(...), -N) wires nested FuncCall + UnaryMinus producer slots. */
    public function testSubstrNestedDechexUsesFuncCallProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
echo substr(dechex(255), -2);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_substr_dechex.php');

        $dechexReturnSlot = null;
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
                $dechexReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $substrSends[] = $op->arg1;
            }
        }

        self::assertNotNull($dechexReturnSlot);
        self::assertCount(2, $substrSends);
        self::assertSame($dechexReturnSlot, $substrSends[0], 'arg sends='.json_encode($substrSends));
        self::assertNotSame($substrSends[0], $substrSends[1], 'arg sends='.json_encode($substrSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('ff', $out);
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

    /** Issue #17478 — file_get_contents($path) after concat assign must wire path variable, not literal prefix. */
    public function testFileGetContentsAfterConcatAssignUsesPathVariableSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$path = '/nope/'.getmypid();
file_get_contents($path);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'file_get_contents_concat_assign.php');

        $concatSlots = [];
        $sendSlots = [];
        $this->collectOpCodesFromBlock($block, $concatSlots, $sendSlots);

        self::assertNotEmpty($concatSlots, 'concat slots='.json_encode($concatSlots));
        self::assertContains($concatSlots[\count($concatSlots) - 1], $sendSlots, 'arg sends='.json_encode($sendSlots));

        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                self::assertNotSame('/nope/', $op->arg1, 'must not misbind concat literal into funccall slot');
            }
        }
    }

    /** Issue #15929 — chained Mul/Div in call operands wires final Div slot, not inner Mul. */
    public function testSprintfMulFloatDivChainUsesFinalDivSlot(): void
    {
        $code = <<<'PHP'
<?php
sprintf('%.10F', 5 * 200.0 / 12);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'sprintf_mul_float_div_chain.php');

        $mulSlot = null;
        $divSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_MUL === $op->type) {
                $mulSlot = $op->arg1;
            }
            if (OpCode::TYPE_DIV === $op->type) {
                $divSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($mulSlot);
        self::assertNotNull($divSlot);
        self::assertSame($divSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($mulSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        ob_get_clean();
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

    /** Issue #22772 — nested options['flags'] ConstFetch + Array_ chain must bind outer Array_. */
    public function testFilterVarNestedOptionsFlagsArraySlots(): void
    {
        $code = <<<'PHP'
<?php
filter_var('01', FILTER_VALIDATE_INT, ['options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_nested_options_flags.php');

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

    /** Issue #22772 — nested options['flags'] must not act as top-level FILTER_FLAG_ALLOW_OCTAL. */
    public function testFilterVarNestedOptionsFlagsRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$nested = filter_var('01', FILTER_VALIDATE_INT, ['options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]]);
$top = filter_var('01', FILTER_VALIDATE_INT, ['flags' => FILTER_FLAG_ALLOW_OCTAL]);
echo false === $nested ? "nested_ok\n" : "nested_bad\n";
echo 1 === $top ? "top_ok\n" : "top_bad\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_nested_options_flags_runtime.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("nested_ok\ntop_ok\n", $out);
    }

    /** Issue #17410 — filter_var() BitwiseOr int flags maps filter const + flags expr to arg slots. */
    public function testFilterVarSanitizeNumberFloatBitmaskFlagsSlots(): void
    {
        $code = <<<'PHP'
<?php
$s = '1,234.5e2';
filter_var(
    $s,
    FILTER_SANITIZE_NUMBER_FLOAT,
    FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_SCIENTIFIC
);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_sanitize_float_flags.php');

        $filterSlot = null;
        $flagsSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $filterSlot) {
                $filterSlot = $op->arg1;
            }
            if (OpCode::TYPE_BITWISE_OR === $op->type) {
                $flagsSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($filterSlot);
        self::assertNotNull($flagsSlot);
        self::assertCount(3, $sendSlots, 'filter_var arg sends='.json_encode($sendSlots));
        self::assertSame($filterSlot, $sendSlots[1] ?? null, 'arg sends='.json_encode($sendSlots));
        self::assertSame($flagsSlot, $sendSlots[2] ?? null, 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #17410 — filter_var() sanitize-number-float + BitwiseOr flags runtime parity. */
    public function testFilterVarSanitizeNumberFloatBitmaskFlagsRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$s = '1,234.5e2';
$r = filter_var(
    $s,
    FILTER_SANITIZE_NUMBER_FLOAT,
    FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_SCIENTIFIC
);
echo $r === '1,234.5e2' ? "ok\n" : "bad\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'filter_var_sanitize_float_flags_runtime.php');
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

    /** Issue #21867 — count($ref->getAttributes(EnumCases::class)) wires MethodCall EXEC_RETURN, not hoisted ::class prelude. */
    public function testCountNestedMethodCallFilteredGetAttributesUsesExecReturnSlot(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesEnumCasesAttributeClass()) {
            self::markTestSkipped('EnumCases attribute requires forward profile 8.4');
        }
        $code = <<<'PHP'
<?php
declare(strict_types=1);

enum Suit {
    #[EnumCases('red')]
    case Hearts;
}
$ref = new ReflectionEnumUnitCase(Suit::class, 'Hearts');
echo count($ref->getAttributes(EnumCases::class)), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'count_enum_case_filtered_get_attributes.php');

        $getAttributesExecSlot = null;
        $countArgSendSlot = null;
        $awaitingGetAttributesExec = false;
        $awaitingCountArgSend = false;
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'getAttributes' === $block->constants[$op->arg2]->toString()
            ) {
                $awaitingGetAttributesExec = true;
            }
            if ($awaitingGetAttributesExec && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $getAttributesExecSlot = $op->arg1;
                $awaitingGetAttributesExec = false;
            }
            if (
                OpCode::TYPE_FUNCCALL_INIT === $op->type
                && null !== $op->arg1
                && isset($block->constants[$op->arg1])
                && 'count' === $block->constants[$op->arg1]->toString()
            ) {
                $awaitingCountArgSend = true;
            }
            if ($awaitingCountArgSend && OpCode::TYPE_ARG_SEND === $op->type) {
                $countArgSendSlot = $op->arg1;
                break;
            }
        }

        self::assertNotNull($getAttributesExecSlot, 'getAttributes must FUNCCALL_EXEC_RETURN');
        self::assertNotNull($countArgSendSlot, 'count() must emit ARG_SEND');
        self::assertSame($getAttributesExecSlot, $countArgSendSlot);

        ob_start();
        $runtime->run($block);
        self::assertSame("1\n", ob_get_clean());
    }

    /** Issue #22693 — count($ref->getAttributes()) with no filter arg must wire MethodCall EXEC_RETURN (re-#21867 bare form). */
    public function testCountNestedMethodCallBareGetAttributesUsesExecReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

enum E {
    #[\Deprecated(message: 'gone')]
    case A;
}
$case = (new ReflectionEnum(E::class))->getCase('A');
echo count($case->getAttributes()), "\n";
echo $case->getAttributes()[0]->getName(), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'count_enum_case_bare_get_attributes.php');

        $getAttributesExecSlot = null;
        $countArgSendSlot = null;
        $awaitingGetAttributesExec = false;
        $awaitingCountArgSend = false;
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'getAttributes' === $block->constants[$op->arg2]->toString()
            ) {
                $awaitingGetAttributesExec = true;
            }
            if ($awaitingGetAttributesExec && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $getAttributesExecSlot = $op->arg1;
                $awaitingGetAttributesExec = false;
            }
            if (
                OpCode::TYPE_FUNCCALL_INIT === $op->type
                && null !== $op->arg1
                && isset($block->constants[$op->arg1])
                && 'count' === $block->constants[$op->arg1]->toString()
            ) {
                $awaitingCountArgSend = true;
            }
            if ($awaitingCountArgSend && OpCode::TYPE_ARG_SEND === $op->type) {
                $countArgSendSlot = $op->arg1;
                break;
            }
        }

        self::assertNotNull($getAttributesExecSlot, 'getAttributes must FUNCCALL_EXEC_RETURN');
        self::assertNotNull($countArgSendSlot, 'count() must emit ARG_SEND');
        self::assertSame($getAttributesExecSlot, $countArgSendSlot);

        ob_start();
        $runtime->run($block);
        self::assertSame("1\nDeprecated\n", ob_get_clean());
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
        self::assertNotSame($arraySlots[0], $sendSlots[0], 'callback must be null, not first array');
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

    /** Issue #16085 — array_map('explode', [','], ['a,b']) wires both inline array slots. */
    public function testArrayMapStringBuiltinMultiInlineArrayLiteralUsesAllSlots(): void
    {
        $code = <<<'PHP'
<?php
array_map('explode', [','], ['a,b']);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_string_builtin_multi_inline.php');

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

    /** Issue #16085 — array_map string builtin multi-array runtime parity. */
    public function testArrayMapStringBuiltinMultiInlineArrayLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_map('explode', [','], ['a,b']));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_string_builtin_multi_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => array (\n    0 => 'a',\n    1 => 'b',\n  ),\n)\n", ob_get_clean());
    }

    /** Issue #16194 — array_udiff_assoc sibling inline arrays wire distinct ARG_SEND slots (#16078 regression). */
    public function testArrayUdiffAssocSiblingInlineArrayLiteralUsesDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
array_udiff_assoc(['a' => 1], ['A' => 1], 'strcasecmp');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_udiff_assoc_sibling_inline.php');

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

        self::assertGreaterThanOrEqual(2, \count($arraySlots), 'array inits='.json_encode($arraySlots));
        self::assertCount(3, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'arg sends='.json_encode($sendSlots));
    }

    /** Issue #16194 — array_udiff_assoc sibling inline arrays runtime parity (re-#11217). */
    public function testArrayUdiffAssocSiblingInlineArrayLiteralRuntime(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_udiff_assoc(['a' => 1], ['A' => 1], 'strcasecmp'));
echo "\n";
var_export(array_udiff_assoc(['a' => 1, 'b' => 2], ['A' => 1, 'c' => 3], 'strcasecmp'));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_udiff_assoc_sibling_inline_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  'a' => 1,\n)\narray (\n  'a' => 1,\n  'b' => 2,\n)\n",
            ob_get_clean()
        );
    }

    /** Issue #15976 — array_map(null, [[..]]) wires null ConstFetch + nested inline Array_. */
    public function testArrayMapNullNestedInlineHaystackUsesDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_map(null, [[1], [2]]));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_null_nested_inline.php');

        $nullSlot = null;
        $mapSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $nullSlot) {
                $nullSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $mapSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mapSends[] = $op->arg1;
            }
        }

        self::assertNotNull($nullSlot);
        self::assertCount(2, $mapSends, 'map sends='.json_encode($mapSends));
        self::assertSame($nullSlot, $mapSends[0], 'null callback slot');
        self::assertNotSame($mapSends[0], $mapSends[1], 'haystack must not reuse callback slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('1', $out);
        self::assertStringContainsString('2', $out);
    }

    /** Issue #16226 — array_map(null, null, [...]) wires callback + null haystack + array slots. */
    public function testArrayMapNullNullHaystackInlineUsesDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
array_map(null, null, [1, 2]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_null_null_haystack.php');

        $nullSlots = [];
        $arraySlot = null;
        $mapSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $nullSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $mapSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mapSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $nullSlots, 'null fetches='.json_encode($nullSlots));
        self::assertNotNull($arraySlot);
        self::assertCount(3, $mapSends, 'map sends='.json_encode($mapSends));
        self::assertSame($nullSlots[0], $mapSends[0], 'callback null slot');
        self::assertSame($nullSlots[1], $mapSends[1], 'haystack null slot');
        self::assertSame($arraySlot, $mapSends[2], 'array haystack slot');
        self::assertNotSame($mapSends[0], $mapSends[1]);
        self::assertNotSame($mapSends[1], $mapSends[2]);
    }

    /** Issue #16226 — array_map(null, null, [...]) runtime TypeError parity. */
    public function testArrayMapNullNullHaystackInlineRuntimeTypeError(): void
    {
        $code = <<<'PHP'
<?php
try {
    array_map(null, null, [1, 2]);
    echo "unexpected success\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_null_null_haystack_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array_map(): Argument #2 (\$array) must be of type array, null given\n",
            ob_get_clean()
        );
    }

    /** Issue #16116 — array_map('strlen', [null]) must not wire haystack null ConstFetch as callback. */
    public function testArrayMapStringBuiltinNullHaystackUsesDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_map('strlen', [null]));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_map_strlen_null_haystack.php');

        $nullSlot = null;
        $mapSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $nullSlot) {
                $nullSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $mapSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $mapSends[] = $op->arg1;
            }
        }

        self::assertNotNull($nullSlot);
        self::assertCount(2, $mapSends, 'map sends='.json_encode($mapSends));
        self::assertNotSame($nullSlot, $mapSends[0], 'callback must not reuse haystack null slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => 0,\n)", ob_get_clean());
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

    /** Regression #10956 — json_encode($s, JSON_HEX_* | …) must send $s then bitmask, not flags twice. */
    public function testJsonEncodeInlineBitmaskFlagsArgSend(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$s = '<>&"\'';
echo json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'json_encode_bitmask.php');

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
        self::assertCount(2, $sendSlots, 'json_encode arg sends='.json_encode($sendSlots));
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'value and flags slots must differ');
        self::assertSame($bitwiseOrSlot, $sendSlots[1] ?? null, 'flags arg sends='.json_encode($sendSlots));
    }

    /** Issue #16152 — get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5) ConstFetch + BitwiseOr slots. */
    public function testGetHtmlTranslationTableConstFetchBitmaskArgSend(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'get_html_translation_table_html5.php');

        $htmlEntitiesSlot = null;
        $bitwiseOrSlot = null;
        $sendSlots = [];
        $captureSends = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $name = $block->constants[$op->arg2]->toString();
                if ('HTML_ENTITIES' === $name) {
                    $htmlEntitiesSlot = $op->arg1;
                }
            }
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

        self::assertNotNull($htmlEntitiesSlot, 'expected HTML_ENTITIES CONST_FETCH slot');
        self::assertNotNull($bitwiseOrSlot, 'expected TYPE_BITWISE_OR slot');
        self::assertSame($htmlEntitiesSlot, $sendSlots[0] ?? null, 'table arg sends='.json_encode($sendSlots));
        self::assertSame($bitwiseOrSlot, $sendSlots[1] ?? null, 'flags arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        ob_end_clean();
    }

    /** Issue #18523 — file_put_contents($f, 'a', FILE_APPEND | LOCK_EX) must send BitwiseOr slot, not prior unlink bool return. */
    public function testFilePutContentsInlineBitmaskAfterUnlinkUsesBitwiseOrSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$f = sys_get_temp_dir() . '/fpc_inline_' . getmypid() . '.txt';
@unlink($f);
$r = file_put_contents($f, 'a', FILE_APPEND | LOCK_EX);
var_dump($r);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'file_put_contents_inline_bitmask.php');

        $bitwiseOrSlot = null;
        $unlinkReturnSlot = null;
        $fpcSendSlots = [];
        $seen = new \SplObjectStorage();
        $walk = static function (Block $cfgBlock) use (&$walk, &$seen, &$bitwiseOrSlot, &$unlinkReturnSlot, &$fpcSendSlots): void {
            if ($seen->contains($cfgBlock)) {
                return;
            }
            $seen->attach($cfgBlock);
            $inFpc = false;
            foreach ($cfgBlock->opCodes as $op) {
                if (OpCode::TYPE_BITWISE_OR === $op->type) {
                    $bitwiseOrSlot = $op->arg1;
                }
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $callee = $cfgBlock->getOperand((int) $op->arg1);
                    $calleeName = $callee instanceof Operand\Literal ? (string) $callee->value : '';
                    if ('unlink' === $calleeName) {
                        $inFpc = false;
                    } elseif ('file_put_contents' === $calleeName) {
                        $inFpc = true;
                        $fpcSendSlots = [];
                    } else {
                        $inFpc = false;
                    }
                }
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    for ($scanIdx = array_search($op, $cfgBlock->opCodes, true) - 1; $scanIdx >= 0; --$scanIdx) {
                        $scan = $cfgBlock->opCodes[$scanIdx] ?? null;
                        if (!$scan instanceof OpCode) {
                            break;
                        }
                        if (OpCode::TYPE_FUNCCALL_INIT === $scan->type) {
                            $callee = $cfgBlock->getOperand((int) $scan->arg1);
                            if ($callee instanceof Operand\Literal && 'unlink' === (string) $callee->value) {
                                $unlinkReturnSlot = $op->arg1;
                            }
                            break;
                        }
                        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $scan->type) {
                            break;
                        }
                    }
                }
                if ($inFpc && OpCode::TYPE_ARG_SEND === $op->type) {
                    $fpcSendSlots[] = $op->arg1;
                }
                if ($inFpc && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    $inFpc = false;
                }
                if (null !== $op->block1) {
                    $walk($op->block1);
                }
                if (null !== $op->block2) {
                    $walk($op->block2);
                }
            }
        };
        $walk($block);

        self::assertNotNull($bitwiseOrSlot, 'expected TYPE_BITWISE_OR slot');
        self::assertNotNull($unlinkReturnSlot, 'expected unlink EXEC_RETURN slot');
        self::assertCount(3, $fpcSendSlots, 'file_put_contents arg sends='.json_encode($fpcSendSlots));
        self::assertSame($bitwiseOrSlot, $fpcSendSlots[2] ?? null, 'flags arg must use BitwiseOr slot');
        self::assertNotSame($unlinkReturnSlot, $fpcSendSlots[2] ?? null, 'must not reuse unlink bool return slot');

        ob_start();
        $runtime->run($block);
        self::assertStringContainsString('int(1)', ob_get_clean());
    }

    /** Issue #18524 — dns_get_record($host, $t = DNS_A | DNS_AAAA) must wire hoisted BitwiseOr slot. */
    public function testDnsGetRecordAssignInCallBitmaskArgSend(): void
    {
        $code = <<<'PHP'
<?php
dns_get_record('php.net', $t = DNS_A | DNS_AAAA);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dns_assign_in_call.php');

        $bitwiseOrSlot = null;
        $dnsSendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_BITWISE_OR === $op->type) {
                $bitwiseOrSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $callee = $block->getOperand((int) $op->arg1);
                if ($callee instanceof Operand\Literal && 'dns_get_record' === (string) $callee->value) {
                    $dnsSendSlots = [];
                }
            }
            if ([] !== $dnsSendSlots || OpCode::TYPE_ARG_SEND === $op->type) {
                if (OpCode::TYPE_ARG_SEND === $op->type) {
                    $dnsSendSlots[] = $op->arg1;
                }
            }
        }

        self::assertNotNull($bitwiseOrSlot, 'expected TYPE_BITWISE_OR slot');
        self::assertCount(2, $dnsSendSlots, 'arg sends='.json_encode($dnsSendSlots));
        self::assertSame($bitwiseOrSlot, $dnsSendSlots[1] ?? null, 'type arg must use BitwiseOr slot');
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

    /** Issue #10731 — IIFE `(function ($g) { … })($gen())` wires hoisted $gen() to __invoke arg #0. */
    public function testIifeHoistedGeneratorCallArgProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
$gen = function () { yield 1; yield 2; };
$fromClosure = (function ($g) { return iterator_to_array($g); })($gen());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'iife_iterator_to_array.php');

        $iifeInitRecv = null;
        $producerReturnSlot = null;
        $sendSlot = null;
        $pendingInitRecv = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                $pendingInitRecv = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                if (null === $producerReturnSlot) {
                    $producerReturnSlot = $op->arg1;
                    continue;
                }
                if (null === $iifeInitRecv) {
                    $iifeInitRecv = $pendingInitRecv;
                }
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlot = $op->arg1;
                if (null === $iifeInitRecv) {
                    $iifeInitRecv = $pendingInitRecv;
                }
            }
        }

        self::assertNotNull($producerReturnSlot, 'missing hoisted $gen() return slot');
        self::assertSame($producerReturnSlot, $sendSlot, 'arg send must use hoisted generator slot');
        self::assertNotSame(1, $iifeInitRecv, 'IIFE __invoke must not target $gen closure slot');
    }

    /** Issue #11321 / #22702 — iterator_to_array(new ArrayObject([...]), false) uses New_ + ConstFetch slots. */
    public function testIteratorToArrayInlineNewWithFalsePreserveKeysUsesNewSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]), false);
echo implode(',', array_keys($a)), "\n";
echo json_encode(array_values($a), JSON_THROW_ON_ERROR), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'iterator_to_array_preserve_false.php');

        $newSlot = null;
        $sendSlots = [];
        $capture = false;
        $sawNewExec = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $newSlot && (string) $op->arg1 === (string) $newSlot) {
                $sawNewExec = true;
            }
            if ($sawNewExec && OpCode::TYPE_FUNCCALL_INIT === $op->type) {
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
        self::assertCount(2, $sendSlots, 'iterator_to_array should send New_ + preserve_keys; sends='.json_encode($sendSlots));
        self::assertSame($newSlot, $sendSlots[0] ?? null, 'arg0 must be New_ slot; sends='.json_encode($sendSlots));
        self::assertNotSame($newSlot, $sendSlots[1] ?? null, 'arg1 must not reuse New_ slot; sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        self::assertSame("0,1\n[1,2]\n", ob_get_clean());
    }

    /** Issue #22702 — iterator_to_array(new ArrayIterator([...]), false) reindexes string keys. */
    public function testIteratorToArrayInlineArrayIteratorPreserveFalseReindexesKeys(): void
    {
        $code = <<<'PHP'
<?php
$a = iterator_to_array(new ArrayIterator(['a' => 1, 'b' => 2]), false);
echo implode(',', array_keys($a)), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'iterator_to_array_arrayiterator_preserve_false.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("0,1\n", ob_get_clean());
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

    /** Issue #27139 — call_user_func_array([A::class, 'm'], [...]) wires distinct Array_ slots. */
    public function testCallUserFuncArrayClassConstCallableAndArgsDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
class CufaClassConstArgsProbe {
    public static function who($x, $y = '') {
        return static::class . ':' . $x . ':' . $y;
    }
}
echo call_user_func_array([CufaClassConstArgsProbe::class, 'who'], ['z', 'w']);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'call_user_func_array_class_const_args.php');

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
        self::assertSame($arraySlots[0], $sendSlots[0], 'callable Array_ must feed arg #0; sends='.json_encode($sendSlots));
        self::assertSame($arraySlots[1], $sendSlots[1], 'args Array_ must feed arg #1; sends='.json_encode($sendSlots));
        self::assertNotSame($sendSlots[0], $sendSlots[1], 'sibling Array_ args must not alias');

        ob_start();
        $runtime->run($block);
        self::assertSame('CufaClassConstArgsProbe:z:w', ob_get_clean());
    }

    /** Issue #18015 — call_user_func_array('fn', [&$x]) must not wire ref dim-fetch to arg #0. */
    public function testCallUserFuncArrayInlineByRefArrayLiteralCallbackSlot(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_call_user_func_array_variadic_byref.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_call_user_func_array_variadic_byref.php');

        $arraySlot = null;
        $dimWriteSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type) {
                $dimWriteSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot, 'array slot missing');
        self::assertNotNull($dimWriteSlot, 'dim write slot missing');
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($dimWriteSlot, $sendSlots[0], 'arg sends='.json_encode($sendSlots));
        self::assertSame($arraySlot, $sendSlots[1], 'arg sends='.json_encode($sendSlots));

        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
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

    /** Issue #18571 — array_replace_recursive() must read assigned locals, not trailing hoisted Array_ roots. */
    public function testArrayReplaceRecursiveAssignedLocalsArgSendWiring(): void
    {
        $code = <<<'PHP'
<?php
$a = ['k' => ['x' => 1, 'y' => 2]];
$b = ['k' => ['y' => 9]];
array_replace_recursive($a, $b);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_assigned_locals.php');

        $assignLvalueSlots = [];
        $initArrayRootSlots = [];
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && null !== $op->arg2) {
                $assignLvalueSlots[] = $op->arg2;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $initArrayRootSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }

        $uniqueAssignLvalues = array_values(array_unique($assignLvalueSlots, SORT_REGULAR));
        self::assertCount(2, $uniqueAssignLvalues, 'assign lvalues='.json_encode($assignLvalueSlots));
        self::assertCount(2, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($uniqueAssignLvalues[0], $sendSlots[0], 'arg #1 must read $a named local');
        self::assertSame($uniqueAssignLvalues[1], $sendSlots[1], 'arg #2 must read $b named local');
        self::assertNotContains($sendSlots[0], $initArrayRootSlots, 'arg #1 must not reuse hoisted Array_ root');
        self::assertNotContains($sendSlots[1], $initArrayRootSlots, 'arg #2 must not reuse hoisted Array_ root');
    }

    /** Issue #18571 — array_replace_recursive() nested merge preserves sibling keys on assigned locals. */
    public function testArrayReplaceRecursiveAssignedLocalsNestedMergeRuntime(): void
    {
        $code = <<<'PHP'
<?php
$a = ['k' => ['x' => 1, 'y' => 2]];
$b = ['k' => ['y' => 9]];
var_export(array_replace_recursive($a, $b));
echo "\n";
$a = ['l' => ['a' => 1, 'b' => ['c' => 3]]];
$b = ['l' => ['b' => ['d' => 4]]];
var_export(array_replace_recursive($a, $b));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_replace_recursive_assigned_locals_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  'k' => array (\n    'x' => 1,\n    'y' => 9,\n  ),\n)\n"
            ."array (\n  'l' => array (\n    'a' => 1,\n    'b' => array (\n      'c' => 3,\n      'd' => 4,\n    ),\n  ),\n)\n",
            ob_get_clean()
        );
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

    /**
     * Issue #23702 — enum-case Array_ + bare PHP_QUERY_RFC3986 must not reuse INIT_ARRAY for encoding_type.
     */
    public function testHttpBuildQueryEnumArrayBareRfc3986ConstUsesDistinctEncodingSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; }
echo http_build_query(['e' => E::A], '', '&', PHP_QUERY_RFC3986), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'http_build_query_enum_rfc3986_const.php');

        $initArraySlot = null;
        $constFetchSlot = null;
        $sendSlots = [];
        $walk = function (Block $b) use (&$walk, &$initArraySlot, &$constFetchSlot, &$sendSlots): void {
            static $seen = [];
            $id = spl_object_id($b);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $initArraySlot) {
                    $initArraySlot = $op->arg1;
                }
                if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constFetchSlot) {
                    $constFetchSlot = $op->arg1;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type) {
                    $sendSlots[] = $op->arg1;
                }
                if (OpCode::TYPE_TRY === $op->type && $op->block1 instanceof Block) {
                    $walk($op->block1);
                }
            }
        };
        $walk($block);

        self::assertNotNull($initArraySlot);
        self::assertNotNull($constFetchSlot);
        self::assertCount(4, $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertSame($initArraySlot, $sendSlots[0], 'data must be INIT_ARRAY slot');
        self::assertSame($constFetchSlot, $sendSlots[3], 'encoding_type must be CONST_FETCH slot, not INIT_ARRAY');
        self::assertNotSame($initArraySlot, $sendSlots[3]);

        ob_start();
        $runtime->run($block);
        self::assertSame("e%5Bname%5D=A&e%5Bvalue%5D=a\n", ob_get_clean());
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
        $lastInitOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                $lastInitOrdinal = $fcallOrdinal;
                if ($lastInitOrdinal > 1) {
                    $sunriseSends = [];
                }
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $timeReturnSlot) {
                $timeReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $constSlot) {
                $constSlot = $op->arg1;
            }
            if ($fcallOrdinal === $lastInitOrdinal && $lastInitOrdinal > 1 && OpCode::TYPE_ARG_SEND === $op->type) {
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

    /** Issue #16012 — date_sunrise(time(), SUNFUNCS_RET_STRING, lat, lon) compiles without OOM and runs. */
    public function testDateSunriseInlineSunfuncsConstFourArgRuntime(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

try {
    $s = date_sunrise(time(), SUNFUNCS_RET_STRING, 40.7, -74.0);
    if (!\is_string($s) || '' === $s) {
        echo "fail: expected non-empty string\n";
        exit(1);
    }
    echo 'ok:', \strlen($s), "\n";
} catch (Throwable $e) {
    echo 'fail:', \get_class($e), ':', $e->getMessage(), "\n";
    exit(1);
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sunrise_inline_sunfuncs_const.php');
        ob_start();
        $runtime->run($block);
        self::assertStringContainsString('ok:', ob_get_clean());
    }

    /** Issue #16640 — multiple date_sunrise/date_sunset calls wire per-call SUNFUNCS + UnaryMinus longitude. */
    public function testDateSunriseSunsetMultiCallHoistedPreludesRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$ts = gmmktime(12, 0, 0, 6, 21, 2020);
$riseStr = date_sunrise($ts, SUNFUNCS_RET_STRING, 40.7, -74.0);
$setStr = date_sunset($ts, SUNFUNCS_RET_STRING, 40.7, -74.0);
echo $riseStr, ':', $setStr, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sunrise_sunset_multi.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("09:23:00:32\n", ob_get_clean());
    }

    /** Issue #11336 — date_sun_info(strtotime(...), lat, lon) wires hoisted FuncCall + UnaryMinus slots. */
    public function testDateSunInfoInlineStrtotimeAndUnaryLongitudeUsesProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
date_sun_info(strtotime('2020-06-21'), 51.5, -0.1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sun_info_inline_strtotime.php');

        $strtotimeReturnSlot = null;
        $sunInfoSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $sunInfoSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $strtotimeReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $sunInfoSends[] = $op->arg1;
            }
        }

        self::assertNotNull($strtotimeReturnSlot, 'strtotime must FUNCCALL_EXEC_RETURN');
        self::assertCount(3, $sunInfoSends, 'arg sends='.json_encode($sunInfoSends));
        self::assertSame($strtotimeReturnSlot, $sunInfoSends[0], 'arg sends='.json_encode($sunInfoSends));
        self::assertSame('-0.1', $block->constants[$sunInfoSends[2] ?? -1]->toString() ?? null, 'arg sends='.json_encode($sunInfoSends));
    }

    /** Issue #11336 — date_sun_info inline strtotime timestamp must match variable form at runtime. */
    public function testDateSunInfoInlineStrtotimeRuntimeMatchesVariableForm(): void
    {
        $code = <<<'PHP'
<?php
$inline = date_sun_info(strtotime('2020-06-21'), 51.5, -0.1);
$t = strtotime('2020-06-21');
$var = date_sun_info($t, 51.5, -0.1);
echo ($inline['sunrise'] === $var['sunrise'] && $inline['sunset'] === $var['sunset']) ? "match\n" : "mismatch\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sun_info_inline_strtotime_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("match\n", ob_get_clean());
    }

    /** Issue #17937 — date_sunrise(strtotime(...), SUNFUNCS_RET_STRING, …) wires hoisted FuncCall + ConstFetch slots. */
    public function testDateSunriseInlineStrtotimeAndSunfuncsConstUsesProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
date_sunrise(strtotime('2026-07-11'), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sunrise_inline_strtotime.php');

        $strtotimeReturnSlot = null;
        $sunriseSends = [];
        $fcallOrdinal = 0;
        $lastInitOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                $lastInitOrdinal = $fcallOrdinal;
                if ($lastInitOrdinal > 1) {
                    $sunriseSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $strtotimeReturnSlot = $op->arg1;
            }
            if ($fcallOrdinal === $lastInitOrdinal && $lastInitOrdinal > 1 && OpCode::TYPE_ARG_SEND === $op->type) {
                $sunriseSends[] = $op->arg1;
            }
        }

        self::assertNotNull($strtotimeReturnSlot, 'strtotime must FUNCCALL_EXEC_RETURN');
        self::assertCount(6, $sunriseSends, 'arg sends='.json_encode($sunriseSends));
        self::assertSame($strtotimeReturnSlot, $sunriseSends[0], 'arg sends='.json_encode($sunriseSends));
        self::assertSame(
            '1',
            $block->constants[$sunriseSends[1] ?? -1]->toString() ?? null,
            'SUNFUNCS_RET_STRING arg sends='.json_encode($sunriseSends)
        );
    }

    /** Issue #17937 — date_sunrise inline strtotime timestamp must match variable form at runtime. */
    public function testDateSunriseInlineStrtotimeRuntimeMatchesVariableForm(): void
    {
        $code = <<<'PHP'
<?php
$inline = date_sunrise(strtotime('2026-07-11'), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
$t = strtotime('2026-07-11');
$var = date_sunrise($t, SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
echo (\is_string($inline) && $inline === $var && preg_match('/^\d{2}:\d{2}$/', $inline)) ? "match\n" : "mismatch\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_sunrise_inline_strtotime_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("match\n", ob_get_clean());
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

    /** Issue #15486 — lone hoisted $assoc ConstFetch must not bind $flags embedded literal. */
    public function testJsonDecodeStrictFlagsTypeErrorNamesStringOperand(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_json_decode_strict_flags_message.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'json_decode_strict_flags_message.php');
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

    /** Issue #18613 — file_get_contents(inline concat, false, null, off, len) wires Concat + ConstFetch slots. */
    public function testFileGetContentsInlineConcatFalseNullOffsetLengthUsesProducerSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$payload = '0123456789';
echo file_get_contents('data://text/plain,'.$payload, false, null, 3, 4);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'file_get_contents_data_offset_inline.php');

        $concatSlots = [];
        $constSlots = [];
        $sendSlots = [];
        $this->collectOpCodesFromBlock($block, $concatSlots, $sendSlots);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlots[] = $op->arg1;
            }
        }

        self::assertNotEmpty($concatSlots, 'concat slots='.json_encode($concatSlots));
        self::assertCount(2, $constSlots, 'const slots='.json_encode($constSlots));
        self::assertContains($concatSlots[0], $sendSlots, 'arg sends='.json_encode($sendSlots));
        self::assertNotSame($constSlots[0], $sendSlots[0] ?? null, 'path arg must not bind to false const');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('3456', $out);
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

    /** Issue #11767 — array_chunk(range(1,5), 2, true) wires range EXEC_RETURN + hoisted true slots. */
    public function testArrayChunkInlineRangeUsesNestedFuncCallAndPreserveKeysSlots(): void
    {
        $code = <<<'PHP'
<?php
array_chunk(range(1, 5), 2, true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_chunk_inline_range.php');

        $rangeReturnSlot = null;
        $chunkSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $chunkSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $rangeReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $chunkSends[] = $op->arg1;
            }
        }

        self::assertNotNull($rangeReturnSlot, 'range must FUNCCALL_EXEC_RETURN');
        self::assertCount(3, $chunkSends, 'array_chunk arg sends='.json_encode($chunkSends));
        self::assertSame($rangeReturnSlot, $chunkSends[0], 'haystack must use range return slot');
        self::assertNotSame($chunkSends[0], $chunkSends[2], 'preserve_keys must not reuse haystack slot');
    }

    /** Issue #11767 — array_chunk inline range runtime parity. */
    public function testArrayChunkInlineRangeRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_chunk_inline_range.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_chunk_inline_range_runtime.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("count=3\n", ob_get_clean());
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

    /** Issue #18185 — @fopen then var_dump($h !== false) must send comparison bool, not stream resource. */
    public function testErrorSuppressAssignThenNotIdenticalInsideCallArgUsesComparisonSlot(): void
    {
        $code = <<<'PHP'
<?php
$h = @fopen('php://memory', 'r+');
var_dump($h !== false);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_comparison_call_arg.php');

        $notIdenticalResultSlot = null;
        $outerSendSlot = null;
        $fcallOrdinal = 0;
        foreach ($this->reachableBlocksFromEntry($block) as $reachable) {
            foreach ($reachable->opCodes as $op) {
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
        }

        self::assertNotNull($notIdenticalResultSlot);
        self::assertSame($notIdenticalResultSlot, $outerSendSlot);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('bool(true)', $out);
    }

    /**
     * Issue #22345 — @$doc->loadHTML(...) then trim($d->saveHTML()) must ARG_SEND the
     * MethodCall result, not the @-suppressed loadHTML bool ("1").
     */
    public function testErrorSuppressThenTrimSaveHtmlUsesMethodCallResultSlot(): void
    {
        $code = <<<'PHP'
<?php
$d = new DOMDocument();
$ok = @$d->loadHTML('<p>x</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo trim($d->saveHTML());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_trim_savehtml.php');

        $saveHtmlReturnSlot = null;
        $trimSendSlot = null;
        $awaitingSaveHtmlExec = false;
        $awaitingTrimSend = false;
        foreach ($this->reachableBlocksFromEntry($block) as $reachable) {
            foreach ($reachable->opCodes as $op) {
                if (
                    OpCode::TYPE_METHODCALL_INIT === $op->type
                    && null !== $op->arg2
                    && (
                        (
                            isset($reachable->constants[$op->arg2])
                            && 'saveHTML' === $reachable->constants[$op->arg2]->toString()
                        )
                        || (
                            isset($block->constants[$op->arg2])
                            && 'saveHTML' === $block->constants[$op->arg2]->toString()
                        )
                    )
                ) {
                    $awaitingSaveHtmlExec = true;
                    continue;
                }
                if (
                    $awaitingSaveHtmlExec
                    && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                    && null !== $op->arg1
                    && null === $saveHtmlReturnSlot
                ) {
                    $saveHtmlReturnSlot = $op->arg1;
                    $awaitingSaveHtmlExec = false;
                }
                if (
                    OpCode::TYPE_FUNCCALL_INIT === $op->type
                    && null !== $op->arg1
                    && (
                        (
                            isset($reachable->constants[$op->arg1])
                            && 'trim' === $reachable->constants[$op->arg1]->toString()
                        )
                        || (
                            isset($block->constants[$op->arg1])
                            && 'trim' === $block->constants[$op->arg1]->toString()
                        )
                    )
                ) {
                    $awaitingTrimSend = true;
                    continue;
                }
                if ($awaitingTrimSend && OpCode::TYPE_ARG_SEND === $op->type && null === $trimSendSlot) {
                    $trimSendSlot = $op->arg1;
                    $awaitingTrimSend = false;
                }
            }
        }

        self::assertNotNull($saveHtmlReturnSlot, 'saveHTML must FUNCCALL_EXEC_RETURN');
        self::assertNotNull($trimSendSlot, 'trim() must emit ARG_SEND');
        self::assertSame($saveHtmlReturnSlot, $trimSendSlot);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('<p>x</p>', trim($out));
    }

    /** @return list<Block> */
    private function reachableBlocksFromEntry(Block $entry): array
    {
        $seen = new \SplObjectStorage();
        $queue = [$entry];
        $reachable = [];
        while ([] !== $queue) {
            $block = array_shift($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            $reachable[] = $block;
            foreach ($block->opCodes as $op) {
                if ($op->block1 instanceof Block && !$seen->contains($op->block1)) {
                    $queue[] = $op->block1;
                }
                if ($op->block2 instanceof Block && !$seen->contains($op->block2)) {
                    $queue[] = $op->block2;
                }
            }
            foreach ($block->blocks as $child) {
                if ($child instanceof Block && !$seen->contains($child)) {
                    $queue[] = $child;
                }
            }
        }

        return $reachable;
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

        $outerHaystackSlot = null;
        $columnSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $op->type && null !== $op->arg1) {
                $outerHaystackSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $columnSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $columnSends[] = $op->arg1;
            }
        }

        self::assertNotNull($outerHaystackSlot);
        self::assertCount(2, $columnSends, 'column sends='.json_encode($columnSends));
        self::assertSame($outerHaystackSlot, $columnSends[0], 'haystack slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => 'a',\n  1 => 'b',\n)\n", ob_get_clean());
    }

    /** Issue #11236 — array_column() inline (object)[] haystack must not bind Cast slot to arg #0. */
    public function testArrayColumnInlineObjectCastHaystackRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$r = array_column([(object) ['id' => 10], (object) ['id' => 20]], 'id');
var_export($r);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_column_object_cast_haystack.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => 10,\n  1 => 20,\n)\n", ob_get_clean());
    }

    /** Issue #15914 — array_column() inline haystack + null column_key + index_key. */
    public function testArrayColumnInlineNullColumnKeyWithIndexKeyRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$r = array_column([['x' => 1, 'y' => 2]], null, 'x');
var_export($r);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_column_null_column_key.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  1 => array (\n    'x' => 1,\n    'y' => 2,\n  ),\n)\n", ob_get_clean());
    }

    /** Issue #16539 — extract([...], flags: EXTR_SKIP) must ARG_SEND array + named flags, not double ConstFetch. */
    public function testExtractNamedFlagsArgSendUsesArrayNotHoistedConst(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$a = 1;
$n = extract(['a' => 99, 'b' => 2], flags: EXTR_SKIP);
echo "n={$n}\n";
echo "a={$a}\n";
echo isset($b) ? "b={$b}\n" : "b=unset\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'extract_flags_named.php');

        $constSlots = [];
        $initArraySlots = [];
        $extractSends = [];
        $inExtract = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlots[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type
                && 'extract' === $block->constants[$op->arg1]->toString()) {
                $inExtract = true;
                continue;
            }
            if ($inExtract && OpCode::TYPE_ARG_SEND === $op->type) {
                $extractSends[] = $op->arg1;
                if (\count($extractSends) >= 2) {
                    $inExtract = false;
                }
            }
            if ($inExtract && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $inExtract = false;
            }
        }

        self::assertNotEmpty($initArraySlots, 'inline array must compile');
        self::assertNotEmpty($constSlots, 'EXTR_SKIP ConstFetch must compile');
        self::assertCount(2, $extractSends, 'extract arg sends='.json_encode($extractSends));
        self::assertSame($initArraySlots[0], $extractSends[0], 'array INIT_ARRAY must feed extract arg #0');
        self::assertSame($constSlots[0], $extractSends[1], 'EXTR_SKIP must feed named flags arg');
        self::assertNotSame($extractSends[0], $extractSends[1], 'array and flags must use distinct slots');

        ob_start();
        $runtime->run($block);
        self::assertSame("n=1\na=1\nb=2\n", ob_get_clean());
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

    /** Issue #14022 — false !== file_exists(null) must ARG_SEND null, not hoisted false literal. */
    public function testFileExistsNullArgSendUsesNullNotComparisonFalseLiteral(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
if (false !== file_exists(null)) {
    echo "fail\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'file_exists_null_arg.php');

        $constSlots = [];
        $argSendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constSlots[] = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $argSendSlots[] = $op->arg1;
            }
        }

        self::assertCount(2, $constSlots);
        self::assertNotEmpty($argSendSlots);
        self::assertSame($constSlots[1], $argSendSlots[0], 'null ConstFetch must feed file_exists arg');
        self::assertNotSame($constSlots[0], $argSendSlots[0], 'hoisted false must not feed file_exists arg');
    }

    /** Issue #17757 — false !== ini_get('bogus') must compare against false, not an uninitialized temp. */
    public function testIniGetUnknownKeyInlineFalseComparisonEmitsConstFetch(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
var_export(false !== ini_get('bogus_xyz'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ini_get_unknown_inline_false.php');

        $falseConstSlot = null;
        $notIdenticalLeft = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $falseConstSlot) {
                $falseConstSlot = $op->arg1;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $notIdenticalLeft = $op->arg2;
            }
        }

        self::assertNotNull($falseConstSlot, 'missing false ConstFetch');
        self::assertSame($falseConstSlot, $notIdenticalLeft, 'NOT_IDENTICAL left must be false literal slot');
    }

    /** Issue #17756 — false !== class_alias(...) must compare against false after EXEC_RETURN. */
    public function testClassAliasInlineFalseComparisonEmitsConstFetch(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
var_export(false !== class_alias('NoSuch17756', 'Alias17756'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_alias_unknown_inline_false.php');

        $falseConstSlot = null;
        $aliasReturnSlot = null;
        $notIdenticalLeft = null;
        $notIdenticalRight = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type && null === $falseConstSlot) {
                $falseConstSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $aliasReturnSlot) {
                $aliasReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $notIdenticalLeft = $op->arg2;
                $notIdenticalRight = $op->arg3;
            }
        }

        self::assertNotNull($falseConstSlot, 'missing false ConstFetch');
        self::assertNotNull($aliasReturnSlot, 'missing class_alias return slot');
        self::assertSame($falseConstSlot, $notIdenticalLeft);
        self::assertSame($aliasReturnSlot, $notIdenticalRight);
    }

    /** Issue #14042 — nested array_reverse() feeds trailing mixed param, not hoisted Array_ prelude. */
    public function testNestedArrayReverseTrailingMixedCallArgUsesFuncCallSlot(): void
    {
        $code = <<<'PHP'
<?php
function out(string $k, mixed $v): void {
    echo $k;
}
out('rev', array_reverse(['a' => 1, 'b' => 2], true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_reverse_trailing_mixed_call_arg.php');

        $reverseReturnSlot = null;
        $outSendSlots = [];
        $pendingSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $reverseReturnSlot) {
                $reverseReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if ([] !== $pendingSends) {
                    $outSendSlots = $pendingSends;
                }
                $pendingSends = [];
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $pendingSends[] = $op->arg1;
            }
        }
        if ([] !== $pendingSends) {
            $outSendSlots = $pendingSends;
        }

        self::assertNotNull($reverseReturnSlot);
        self::assertSame($reverseReturnSlot, $outSendSlots[1] ?? null, 'out() must send array_reverse return slot');
    }

    /** Issue #14042 — runtime parity for array_reverse + array_slice in one compile unit. */
    public function testArrayReverseWithSliceSameUnitRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function out(string $k, mixed $v): void {
    echo $k . '=' . (is_string($v) ? $v : var_export($v, true)) . "\n";
}
$ks = ['10' => 1, '2' => 2];
krsort($ks, SORT_NUMERIC);
out('array_reverse', array_reverse(['a' => 1, 'b' => 2], true));
out('array_slice', array_slice(['a' => 1, 'b' => 2, 'c' => 3], 1, 2, true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_reverse_with_slice_same_unit.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();

        self::assertStringContainsString("'b' => 2", $out);
        self::assertStringContainsString("'a' => 1", $out);
        self::assertStringContainsString("'c' => 3", $out);
        self::assertStringNotContainsString('NULL', $out);
    }

    /** Issue #14555 — chained dim-fetch / inline method-call feed sole dead call-arg temp. */
    public function testSingleDeadCallArgUsesImmediateHoistedProducerNotDistantPrelude(): void
    {
        $code = <<<'PHP'
<?php
function inner(): void {
    $f = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    echo is_string($f[0]['function']) ? "bt_ok\n" : "bt_bad\n";
}
inner();
$ao = new ArrayObject(['a' => 1]);
echo is_array($ao->getArrayCopy()) ? "ao_ok\n" : "ao_bad\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'inline_call_arg_chained_dim_fetch.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('bt_ok', $out);
        self::assertStringContainsString('ao_ok', $out);
        self::assertStringNotContainsString('bt_bad', $out);
        self::assertStringNotContainsString('ao_bad', $out);
    }

    /** Issue #15762 — var_export($a[1][0], true) wires chained dim-fetch tail, not outer tuple. */
    public function testVarExportChainedDimFetchWithLiteralReturnFlagUsesTailProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$b = [1 => [0 => 'a', 1 => 0]];
echo var_export($b[1][0], true), "\n";
preg_match('/(a)(b)/', 'ab', $m, PREG_OFFSET_CAPTURE);
echo var_export($m[1][0], true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_export_chained_dim_fetch.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("'a'\n'a'\n", $out);
    }

    /** Issue #16461 — var_export('abc'[-1], true) wires dim-fetch char, not UnaryMinus dim index. */
    public function testVarExportStringNegativeOffsetInlineDimFetchUsesFetchResultSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export('abc'[-1], true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'string_negative_offset_var_export.php');

        $dimFetchResultSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                $dimFetchResultSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($dimFetchResultSlot);
        self::assertCount(2, $varExportSends);
        self::assertSame($dimFetchResultSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("'c'", $out);
    }

    /** Issue #16738 — var_export($b[false]) must wire dim-fetch NULL, not hoisted false dim index. */
    public function testVarExportArrayBoolDimFetchUsesFetchResultSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$b = ['a' => 1];
var_export($b[false]);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_bool_dim_var_export.php');

        $dimFetchResultSlot = null;
        $varExportSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                $dimFetchResultSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $varExportSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $varExportSends[] = $op->arg1;
            }
        }

        self::assertNotNull($dimFetchResultSlot);
        self::assertCount(1, $varExportSends);
        self::assertSame($dimFetchResultSlot, $varExportSends[0], 'arg sends='.json_encode($varExportSends));

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("NULL\n", $out);
    }

    /** Issue #14828 — DateTimeZone::getTransitions(strtotime(), strtotime()) distinct arg slots. */
    public function testDateTimeZoneGetTransitionsDualInlineStrtotimeUsesDistinctArgSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$tz = new DateTimeZone('Europe/Berlin');
$trans = $tz->getTransitions(strtotime('2024-01-01'), strtotime('2024-06-01'));
echo count($trans), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'datetimezone_get_transitions_inline.php');

        $methodArgSendSlots = [];
        $seenMethodInit = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                $seenMethodInit = true;
                continue;
            }
            if ($seenMethodInit && OpCode::TYPE_ARG_SEND === $op->type) {
                $methodArgSendSlots[] = $op->arg1;
            }
            if ($seenMethodInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(2, $methodArgSendSlots, 'method arg sends='.json_encode($methodArgSendSlots));
        self::assertNotSame($methodArgSendSlots[0], $methodArgSendSlots[1]);

        ob_start();
        $runtime->run($block);
        self::assertSame("2\n", ob_get_clean());
    }

    /** Issue #16057 — DOMNode::C14NFile($tmp) on property-fetch receiver must not reuse receiver slot for uri arg. */
    public function testDomNodeC14NFilePropertyFetchReceiverVariableArgUsesDistinctSlot(): void
    {
        $code = <<<'PHP'
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com"><child>text</child></root>');
$expected = '<root xmlns="http://example.com"><child>text</child></root>';
$tmp = tempnam(sys_get_temp_dir(), 'domc14n');
$bytes = $doc->documentElement->C14NFile($tmp);
echo 'ok bytes=', $bytes, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_c14nfile_property_fetch_var.php');

        $methodInitReceiver = null;
        $methodArgSendSlot = null;
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_ARG_SEND === $op->type && null === $methodArgSendSlot) {
                $methodArgSendSlot = $op->arg1;
                continue;
            }
            if (OpCode::TYPE_METHODCALL_INIT === $op->type && null === $methodInitReceiver) {
                $methodInitReceiver = $op->arg1;
                break;
            }
        }

        self::assertNotNull($methodInitReceiver, 'missing C14NFile receiver slot');
        self::assertNotNull($methodArgSendSlot, 'missing C14NFile uri ARG_SEND');
        self::assertNotSame($methodInitReceiver, $methodArgSendSlot, 'uri arg must not alias property-fetch receiver slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("ok bytes=59\n", ob_get_clean());
    }

    /** Issue #16318 — importNode($doc->documentElement, true) must not misbind deep to property-fetch slot. */
    public function testDomDocumentImportNodePropertyFetchAndTrueUseDistinctArgSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$doc1 = new DOMDocument();
$doc1->loadXML('<x>text</x>');
$doc2 = new DOMDocument();
$imported = $doc2->importNode($doc1->documentElement, true);
echo $imported->nodeName, ':', $imported->textContent, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_import_node_property_fetch_true.php');

        $importArgSendSlots = [];
        $seenImportNode = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'importNode' === $block->constants[$op->arg2]->toString()) {
                $seenImportNode = true;
                continue;
            }
            if ($seenImportNode && OpCode::TYPE_ARG_SEND === $op->type) {
                $importArgSendSlots[] = $op->arg1;
            }
            if ($seenImportNode && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(2, $importArgSendSlots, 'importNode arg sends='.json_encode($importArgSendSlots));
        self::assertNotSame($importArgSendSlots[0], $importArgSendSlots[1]);

        ob_start();
        $runtime->run($block);
        self::assertSame("x:text\n", ob_get_clean());
    }

    /** Issue #20284 — importNode($list->item(0), true) must not misbind deep to MethodCall result. */
    public function testDomDocumentImportNodeMethodCallChainAndTrueUseDistinctArgSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$doc1 = new DOMDocument();
$doc1->loadXML('<root><a><b>x</b></a></root>');
$doc2 = new DOMDocument();
$doc2->loadXML('<root/>');
$imported = $doc2->importNode($doc1->getElementsByTagName('a')->item(0), true);
$doc2->documentElement->appendChild($imported);
echo $doc2->saveXML();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_import_node_methodcall_true.php');

        $importArgSendSlots = [];
        $seenImportNode = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'importNode' === $block->constants[$op->arg2]->toString()) {
                $seenImportNode = true;
                continue;
            }
            if ($seenImportNode && OpCode::TYPE_ARG_SEND === $op->type) {
                $importArgSendSlots[] = $op->arg1;
            }
            if ($seenImportNode && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(2, $importArgSendSlots, 'importNode arg sends='.json_encode($importArgSendSlots));
        self::assertNotSame($importArgSendSlots[0], $importArgSendSlots[1]);
        $deepSlot = $importArgSendSlots[1];
        $deepIsConstFetchTrue = false;
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_CONST_FETCH === $op->type
                && (string) $op->arg1 === (string) $deepSlot
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'true' === strtolower($block->constants[$op->arg2]->toString())
            ) {
                $deepIsConstFetchTrue = true;
                break;
            }
        }
        self::assertTrue($deepIsConstFetchTrue, 'arg #1 must be ConstFetch true, got slot='.$deepSlot);

        ob_start();
        $runtime->run($block);
        self::assertSame("<?xml version=\"1.0\"?>\n<root><a><b>x</b></a></root>\n", ob_get_clean());
    }

    /**
     * Issue #25876 — parentNode->replaceChild($b->cloneNode(true), $a) must bind ConstFetch true
     * to cloneNode, not the outer MethodCall's PropertyFetch receiver.
     */
    public function testDomNodeNestedCloneNodeBoolLiteralUsesConstFetchNotOuterReceiver(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r><a>1</a><b>2</b></r>');
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
$a->parentNode->replaceChild($b->cloneNode(true), $a);
echo $d->C14N(), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_nested_clonenode_bool_25876.php');

        $cloneArgSendSlot = null;
        $seenCloneNode = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'cloneNode' === $block->constants[$op->arg2]->toString()) {
                $seenCloneNode = true;
                continue;
            }
            if ($seenCloneNode && OpCode::TYPE_ARG_SEND === $op->type) {
                $cloneArgSendSlot = $op->arg1;
                break;
            }
        }
        self::assertNotNull($cloneArgSendSlot, 'missing cloneNode ARG_SEND');

        $deepIsConstFetchTrue = false;
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_CONST_FETCH === $op->type
                && (string) $op->arg1 === (string) $cloneArgSendSlot
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'true' === strtolower($block->constants[$op->arg2]->toString())
            ) {
                $deepIsConstFetchTrue = true;
                break;
            }
        }
        self::assertTrue(
            $deepIsConstFetchTrue,
            'cloneNode deep must be ConstFetch true, got slot='.$cloneArgSendSlot
        );

        ob_start();
        $runtime->run($block);
        self::assertSame("<r><b>2</b><b>2</b></r>\n", ob_get_clean());
    }

    /**
     * Issue #25841 — var_export($e->getAttributeNode(...)->isId()) must emit getAttributeNode
     * before isId (1-arg FuncCall must not defer the chain receiver as a multi-arg sibling).
     */
    public function testVarExportChainedGetAttributeNodeIsIdEmitsReceiverMethodCall(): void
    {
        $code = <<<'PHP'
<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('class', 'c');
var_export($e->getAttributeNode('class')->isId());
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_attr_isid_chain_var_export_25841.php');

        $sawGetAttributeNode = false;
        $sawIsId = false;
        $getAttributeNodeBeforeIsId = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type
                || null === $op->arg2
                || !isset($block->constants[$op->arg2])) {
                continue;
            }
            $method = $block->constants[$op->arg2]->toString();
            if ('getAttributeNode' === $method) {
                $sawGetAttributeNode = true;
            }
            if ('isId' === $method) {
                $sawIsId = true;
                $getAttributeNodeBeforeIsId = $sawGetAttributeNode;
            }
        }
        self::assertTrue($sawGetAttributeNode, 'getAttributeNode METHODCALL_INIT missing');
        self::assertTrue($sawIsId, 'isId METHODCALL_INIT missing');
        self::assertTrue($getAttributeNodeBeforeIsId, 'getAttributeNode must precede isId');

        ob_start();
        $runtime->run($block);
        self::assertSame("false\n", ob_get_clean());
    }

    /**
     * Issue #25841 — echo var_export($e->getAttributeNode(...)->isId(), true) two-arg form.
     */
    public function testVarExportTrueChainedGetAttributeNodeIsIdEmitsReceiverMethodCall(): void
    {
        $code = <<<'PHP'
<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('class', 'c');
echo var_export($e->getAttributeNode('class')->isId(), true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_attr_isid_chain_var_export_true_25841.php');

        $sawGetAttributeNode = false;
        $sawIsId = false;
        $getAttributeNodeBeforeIsId = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type
                || null === $op->arg2
                || !isset($block->constants[$op->arg2])) {
                continue;
            }
            $method = $block->constants[$op->arg2]->toString();
            if ('getAttributeNode' === $method) {
                $sawGetAttributeNode = true;
            }
            if ('isId' === $method) {
                $sawIsId = true;
                $getAttributeNodeBeforeIsId = $sawGetAttributeNode;
            }
        }
        self::assertTrue($sawGetAttributeNode, 'getAttributeNode METHODCALL_INIT missing');
        self::assertTrue($sawIsId, 'isId METHODCALL_INIT missing');
        self::assertTrue($getAttributeNodeBeforeIsId, 'getAttributeNode must precede isId');

        ob_start();
        $runtime->run($block);
        self::assertSame("false\n", ob_get_clean());
    }

    /**
     * Issue #25928 — loadXML + documentElement (UNKNOWN-typed getAttributeNode) before
     * var_export(..., true) must still emit getAttributeNode before isId (re-#25841 residual).
     */
    public function testLoadXmlVarExportChainedGetAttributeNodeIsIdEmitsReceiverMethodCall(): void
    {
        $code = <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><e myid="x" class="c">1</e></r>');
$e = $d->documentElement->firstChild;
$e->setIdAttribute('myid', true);
echo var_export($e->getAttributeNode('myid')->isId(), true), "\n";
echo var_export($e->getAttributeNode('class')->isId(), true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_attr_isid_chain_loadxml_25928.php');

        $sawGetAttributeNode = false;
        $sawIsId = false;
        $getAttributeNodeBeforeIsId = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type
                || null === $op->arg2
                || !isset($block->constants[$op->arg2])) {
                continue;
            }
            $method = $block->constants[$op->arg2]->toString();
            if ('getAttributeNode' === $method) {
                $sawGetAttributeNode = true;
            }
            if ('isId' === $method) {
                $sawIsId = true;
                $getAttributeNodeBeforeIsId = $sawGetAttributeNode;
            }
        }
        self::assertTrue($sawGetAttributeNode, 'getAttributeNode METHODCALL_INIT missing');
        self::assertTrue($sawIsId, 'isId METHODCALL_INIT missing');
        self::assertTrue($getAttributeNodeBeforeIsId, 'getAttributeNode must precede isId');

        ob_start();
        $runtime->run($block);
        self::assertSame("true\nfalse\n", ob_get_clean());
    }

    /** Issue #24571 — appendChild(createElement) before importNode(documentElement, true) must not bind deep to the element. */
    public function testDomImportNodeAfterAppendChildCreateElementUsesPropertyFetchAndTrueSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$src = new DOMDocument();
$src->loadXML('<r><a><b>t</b></a></r>');
$dst = new DOMDocument('1.0');
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '/', $n->childNodes->length, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_import_node_after_append_24571.php');

        $importArgSendSlots = [];
        $seenImportNode = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'importNode' === $block->constants[$op->arg2]->toString()) {
                $seenImportNode = true;
                continue;
            }
            if ($seenImportNode && OpCode::TYPE_ARG_SEND === $op->type) {
                $importArgSendSlots[] = $op->arg1;
            }
            if ($seenImportNode && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(2, $importArgSendSlots, 'importNode arg sends='.json_encode($importArgSendSlots));
        self::assertNotSame($importArgSendSlots[0], $importArgSendSlots[1]);

        $nodeSlot = $importArgSendSlots[0];
        $deepSlot = $importArgSendSlots[1];
        $nodeIsPropertyFetch = false;
        $deepIsConstFetchTrue = false;
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_PROPERTY_FETCH === $op->type
                && (string) $op->arg1 === (string) $nodeSlot
            ) {
                $nodeIsPropertyFetch = true;
            }
            if (
                OpCode::TYPE_CONST_FETCH === $op->type
                && (string) $op->arg1 === (string) $deepSlot
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'true' === strtolower($block->constants[$op->arg2]->toString())
            ) {
                $deepIsConstFetchTrue = true;
            }
        }
        self::assertTrue($nodeIsPropertyFetch, 'arg #0 must be PropertyFetch documentElement, got slot='.$nodeSlot);
        self::assertTrue($deepIsConstFetchTrue, 'arg #1 must be ConstFetch true, got slot='.$deepSlot);

        ob_start();
        $runtime->run($block);
        self::assertSame("r/1\n", ob_get_clean());
    }

    /** Issue #25563 — replaceChild(createElement, getElementsByTagName()->item()) must bind createElement + item. */
    public function testDomReplaceChildCreateElementAndGetElementsByTagNameItemUsesDistinctExecReturnSlots(): void
    {
        $code = <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><a/><b/></r>');
$list = $d->getElementsByTagName('a');
$d->documentElement->replaceChild(
    $d->createElement('a'),
    $d->getElementsByTagName('b')->item(0)
);
echo $list->length, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_replacechild_inline_item_25563.php');

        $replaceArgSendSlots = [];
        $seenReplaceChild = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'replaceChild' === $block->constants[$op->arg2]->toString()) {
                $seenReplaceChild = true;
                continue;
            }
            if ($seenReplaceChild && OpCode::TYPE_ARG_SEND === $op->type) {
                $replaceArgSendSlots[] = $op->arg1;
            }
            if ($seenReplaceChild && (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type)
                && \count($replaceArgSendSlots) >= 2) {
                break;
            }
        }

        self::assertCount(2, $replaceArgSendSlots, 'replaceChild arg sends='.json_encode($replaceArgSendSlots));
        self::assertNotSame($replaceArgSendSlots[0], $replaceArgSendSlots[1]);

        $createElementSlot = null;
        $itemSlot = null;
        $pendingCreate = false;
        $pendingItem = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])) {
                $name = $block->constants[$op->arg2]->toString();
                $pendingCreate = ('createElement' === $name);
                $pendingItem = ('item' === $name);
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                if ($pendingCreate && null === $createElementSlot) {
                    $createElementSlot = $op->arg1;
                }
                if ($pendingItem) {
                    $itemSlot = $op->arg1;
                }
                $pendingCreate = false;
                $pendingItem = false;
            }
        }
        self::assertNotNull($createElementSlot, 'createElement must EXEC_RETURN');
        self::assertNotNull($itemSlot, 'item must EXEC_RETURN');
        self::assertSame((string) $createElementSlot, (string) $replaceArgSendSlots[0], 'arg #0 must be createElement result');
        self::assertSame((string) $itemSlot, (string) $replaceArgSendSlots[1], 'arg #1 must be item result');

        ob_start();
        $runtime->run($block);
        self::assertSame("3\n", ob_get_clean());
    }

    /**
     * Issue #25842 — echo getElementsByTagName()->item()->getLineNo() must emit getElementsByTagName
     * (0/1-arg method finals are not multi-arg sibling consumers that defer the receiver chain).
     */
    public function testDomChainEchoItemGetLineNoEmitsGetElementsByTagName(): void
    {
        $code = <<<'PHP'
<?php
$d = new DOMDocument();
$d->loadXML("<r>\n<a id=\"x\"/>\n</r>");
echo $d->getElementsByTagName('a')->item(0)->getLineNo(), "\n";
echo $d->getElementsByTagName('a')->item(0)->getAttribute('id'), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_chain_echo_item_method_25842.php');

        $getElementsInits = 0;
        $itemInits = 0;
        $getLineNoInits = 0;
        $getAttributeInits = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type
                || null === $op->arg2
                || !isset($block->constants[$op->arg2])
            ) {
                continue;
            }
            $name = $block->constants[$op->arg2]->toString();
            if ('getElementsByTagName' === $name) {
                ++$getElementsInits;
            } elseif ('item' === $name) {
                ++$itemInits;
            } elseif ('getLineNo' === $name) {
                ++$getLineNoInits;
            } elseif ('getAttribute' === $name) {
                ++$getAttributeInits;
            }
        }
        self::assertSame(2, $getElementsInits, 'both getElementsByTagName calls must METHODCALL_INIT');
        self::assertSame(2, $itemInits, 'both item() calls must METHODCALL_INIT');
        self::assertSame(1, $getLineNoInits);
        self::assertSame(1, $getAttributeInits);

        ob_start();
        $runtime->run($block);
        self::assertSame("2\nx\n", ob_get_clean());
    }

    /**
     * Issue #25949 — getElementsByTagName()->item()->appendChild($n) before a multi-arg user FuncCall
     * must emit item before appendChild (do not defer item as a sibling of dump23514).
     */
    public function testDomItemAppendChildBeforeUserFuncCallKeepsItemBeforeAppendChild(): void
    {
        $code = <<<'PHP'
<?php
function dump23514($label, $html, $div) { echo $label, "\n"; }
$xml = new DOMDocument();
$xml->loadXML('<div id="w">x</div>');
$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n = $html->importNode($xml->documentElement, true);
$html->getElementsByTagName('body')->item(0)->appendChild($n);
dump23514('xml2html', $html, $n);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_item_appendchild_before_func_25949.php');

        $itemInitAt = null;
        $appendInitAt = null;
        foreach ($block->opCodes as $i => $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type
                || null === $op->arg2
                || !isset($block->constants[$op->arg2])
            ) {
                continue;
            }
            $name = $block->constants[$op->arg2]->toString();
            if ('item' === $name && null === $itemInitAt) {
                $itemInitAt = $i;
            } elseif ('appendChild' === $name && null === $appendInitAt) {
                $appendInitAt = $i;
            }
        }
        self::assertNotNull($itemInitAt, 'item() METHODCALL_INIT missing');
        self::assertNotNull($appendInitAt, 'appendChild METHODCALL_INIT missing');
        self::assertLessThan($appendInitAt, $itemInitAt, 'item() must precede appendChild');

        ob_start();
        $runtime->run($block);
        self::assertSame("xml2html\n", ob_get_clean());
    }

    /**
     * Issue #25605 — importNode(getElementsByTagName()->item(), true) must not drop preceding loadXML
     * (re-#20284; regression from #25563 dead-temp multi-arg feed matching loadXML).
     */
    public function testDomImportNodeAfterLoadXmlKeepsDocumentElementAndBothLoadXmlInits(): void
    {
        $code = <<<'PHP'
<?php
$d1 = new DOMDocument();
$d1->loadXML('<root><a><b>x</b></a></root>');
$d2 = new DOMDocument();
$d2->loadXML('<root/>');
$n = $d2->importNode($d1->getElementsByTagName('a')->item(0), true);
$d2->documentElement->appendChild($n);
echo $d2->saveXML();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_importnode_loadxml_25605.php');

        $loadXmlInits = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'loadXML' === $block->constants[$op->arg2]->toString()
            ) {
                ++$loadXmlInits;
            }
        }
        self::assertSame(2, $loadXmlInits, 'both loadXML calls must remain METHODCALL_INIT');

        ob_start();
        $runtime->run($block);
        self::assertSame("<?xml version=\"1.0\"?>\n<root><a><b>x</b></a></root>\n", ob_get_clean());
    }

    /** Issue #18410 — documentElement->appendChild(createElement) must not feed receiver fetch into inner arg. */
    public function testDomDocumentElementAppendChildCreateElementUsesDistinctArgSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$doc->documentElement->appendChild($doc->createElement('a'));
echo 'after=', $list->length, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_nodelist_live_append_create_element.php');

        $createElementArgSendSlot = null;
        $documentElementFetchSlot = null;
        $opCodes = $block->opCodes;
        $total = \count($opCodes);
        for ($i = 0; $i < $total; ++$i) {
            $op = $opCodes[$i];
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type
                || null === $op->arg2
                || !isset($block->constants[$op->arg2])
                || 'createElement' !== $block->constants[$op->arg2]->toString()) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; --$j) {
                if (OpCode::TYPE_PROPERTY_FETCH === $opCodes[$j]->type) {
                    $documentElementFetchSlot = $opCodes[$j]->arg1;
                    break;
                }
            }
            for ($j = $i + 1; $j < $total; ++$j) {
                if (OpCode::TYPE_ARG_SEND === $opCodes[$j]->type) {
                    $createElementArgSendSlot = $opCodes[$j]->arg1;
                    break;
                }
            }
            break;
        }

        self::assertNotNull($documentElementFetchSlot, 'missing documentElement fetch slot');
        self::assertNotNull($createElementArgSendSlot, 'missing createElement ARG_SEND');
        self::assertNotSame(
            $documentElementFetchSlot,
            $createElementArgSendSlot,
            'createElement tag arg must not alias documentElement fetch slot'
        );

        ob_start();
        $runtime->run($block);
        self::assertSame("before=1\nafter=2\n", ob_get_clean());
    }

    /** Issue #15996 — DateTime literal ctor arg must not alias prior inline NEW slot. */
    public function testDateTimeNewLiteralArgDistinctFromPriorNewResultSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$tz = new DateTimeZone('UTC');
$dt = new DateTime('2020-01-01 12:00:00', $tz);
echo $dt->format('c'), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'datetime_new_timezone_var.php');

        $dateTimeArgSends = [];
        $seenDateTimeNew = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $classSlot = $op->arg2;
                if (null !== $classSlot && isset($block->constants[$classSlot])) {
                    $className = $block->constants[$classSlot]->toString();
                    if ('DateTime' === $className) {
                        $seenDateTimeNew = true;
                        continue;
                    }
                }
            }
            if ($seenDateTimeNew && OpCode::TYPE_ARG_SEND === $op->type) {
                $dateTimeArgSends[] = $op->arg1;
            }
            if ($seenDateTimeNew && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
        }

        self::assertCount(2, $dateTimeArgSends, 'DateTime ctor arg sends='.json_encode($dateTimeArgSends));
        self::assertNotSame($dateTimeArgSends[0], $dateTimeArgSends[1]);

        ob_start();
        $runtime->run($block);
        self::assertSame("2020-01-01T12:00:00+00:00\n", ob_get_clean());
    }

    /** Issue #18456 — array_key_exists() inline new must wire New_ slot, not ctor Array_ prelude. */
    public function testArrayKeyExistsInlineNewUsesNewProducerSlot(): void
    {
        $code = <<<'PHP'
<?php
array_key_exists(0, new ArrayObject([1]));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_key_exists_inline_new.php');

        $arraySlot = null;
        $newSlot = null;
        $akeSends = [];
        $inArrayKeyExists = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $arraySlot) {
                $arraySlot = $op->arg1;
            }
            if (OpCode::TYPE_NEW === $op->type && null === $newSlot) {
                $newSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $inArrayKeyExists = true;
                $akeSends = [];
            }
            if ($inArrayKeyExists && OpCode::TYPE_ARG_SEND === $op->type) {
                $akeSends[] = $op->arg1;
            }
        }

        self::assertNotNull($arraySlot, 'ctor array slot');
        self::assertNotNull($newSlot, 'inline new slot');
        self::assertCount(2, $akeSends, 'array_key_exists arg sends='.json_encode($akeSends));
        self::assertSame($newSlot, $akeSends[1] ?? null, 'haystack arg must use New_ slot, not ctor Array_');
        self::assertNotSame($arraySlot, $akeSends[1] ?? null);
    }

    /** Issue #15422 — in_array/array_search/array_key_exists after UDF with array param. */
    public function testInArrayFamilyAfterUdfArrayParamRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_in_array_after_udf_array.php');
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_after_udf_array.php');

        $holdExecSlot = null;
        $inArrayHaystackSend = null;
        $fcallOrdinal = 0;
        $currentFcallSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if (3 === \count($currentFcallSends)) {
                    $inArrayHaystackSend = $currentFcallSends[1];
                }
                ++$fcallOrdinal;
                $currentFcallSends = [];
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $holdExecSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $currentFcallSends[] = $op->arg1;
            }
        }
        if (3 === \count($currentFcallSends) && null === $inArrayHaystackSend) {
            $inArrayHaystackSend = $currentFcallSends[1];
        }

        self::assertNotNull($inArrayHaystackSend, 'in_array haystack ARG_SEND missing');
        if (null !== $holdExecSlot) {
            self::assertNotSame($holdExecSlot, $inArrayHaystackSend, 'haystack must not reuse hold() return slot');
        }

        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    /** Issue #15421 — array_pad negative length after UDF with array param. */
    public function testArrayPadNegativeLengthAfterUdfArrayParamRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_array_pad_neg_after_udf_array.php');
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_pad_neg_after_udf_array.php');

        $padArgSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $padArgSends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $padArgSends[] = $op->arg1;
            }
        }

        self::assertCount(3, $padArgSends);
        self::assertNotSame($padArgSends[0], $padArgSends[1], 'array and length must use distinct slots');

        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    /** Issue #9329 — array_splice($a, -2, 1, ['x']) wires UnaryMinus offset + replacement Array_. */
    public function testArraySpliceNegativeOffsetReplacementRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$a = [0, 1, 2, 3, 4];
array_splice($a, -2, 1, ['x']);
var_export($a);
echo "\n";

$b = [0, 1, 2, 3, 4];
array_splice($b, 2, 1, ['x']);
var_export($b);
echo "\n";

$c = [0, 1, 2, 3, 4];
array_splice($c, -2, 1);
var_export($c);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_splice_neg_repl.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  0 => 0,\n  1 => 1,\n  2 => 2,\n  3 => 'x',\n  4 => 4,\n)\n"
            ."array (\n  0 => 0,\n  1 => 1,\n  2 => 'x',\n  3 => 3,\n  4 => 4,\n)\n"
            ."array (\n  0 => 0,\n  1 => 1,\n  2 => 2,\n  3 => 4,\n)\n",
            ob_get_clean()
        );
    }

    /** Issue #16328 — array_splice($a, -3, 2, null) wires UnaryMinus offset + null replacement (#10589). */
    public function testArraySpliceNegativeOffsetNullReplacementRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$a = [1, 2, 3, 4];
array_splice($a, -3, 2, null);
var_export($a);
echo "\n";

foreach (['usort', 'uasort', 'uksort'] as $fn) {
    try {
        if ('usort' === $fn) {
            $arr = [1, 2];
            $fn($arr, null);
        } else {
            $arr = [1 => 2, 3 => 4];
            $fn($arr, null);
        }
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_splice_neg_null_repl.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "array (\n  0 => 1,\n  1 => 4,\n)\n"
            ."usort: TypeError\n"
            ."uasort: TypeError\n"
            ."uksort: TypeError\n",
            ob_get_clean()
        );
    }

    /** Issue #17598 — stmt-level substr(..., -N) must not wire prior discard as nested haystack for 3-arg substr. */
    public function testSubstrNegativeLengthAfterDiscardedNegativeOffsetRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
substr('hello', -3);
echo substr('hello', 0, -2), "\n";
echo substr('abcdef', -4, 2), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'substr_neg_len_after_offset.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("hel\ncd\n", ob_get_clean());
    }

    /** Issue #16481 — mb_substr/mb_strcut($s, -N, null[, $enc]) wires UnaryMinus offset + null length. */
    public function testMbstringNegativeOffsetNullLengthRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

echo mb_substr('αβγ', -2, null, 'UTF-8'), "\n";
echo mb_strcut('日本語テスト', -3, null, 'UTF-8'), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'mb_substr_neg_null_len.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("βγ\nト\n", ob_get_clean());
    }

    /** Issue #9292 — && merge phi must not clobber nested stream_set_blocking var_dump arg. */
    public function testStreamSetBlockingAfterLogicalAndUsesNestedCallSlot(): void
    {
        $code = <<<'PHP'
<?php
$f = tmpfile();
$meta = stream_get_meta_data($f);
$x = isset($meta['wrapper_type']) && isset($meta['stream_type']);
var_dump(stream_set_blocking($f, true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'stream_set_blocking_after_and.php');

        ob_start();
        $runtime->run($block);
        self::assertSame("bool(true)\n", ob_get_clean());
    }

    /** Issue #18186 — stream_set_blocking($pipes[1], false) wires dim-fetch + hoisted false, not duplicate resource. */
    public function testStreamSetBlockingProcPipeDimFetchAndFalseUseDistinctArgSlots(): void
    {
        $code = <<<'PHP'
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('true', $desc, $pipes);
stream_set_blocking($pipes[1], false);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'stream_set_blocking_proc_pipe.php');

        $dimFetchSlot = null;
        $falseSlot = null;
        $blockingSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $blockingSends = [];
                }
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type && 2 === $fcallOrdinal) {
                $blockingSends[] = $op->arg1;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                $dimFetchSlot = $op->arg1;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $falseSlot = $op->arg1;
            }
        }

        self::assertNotNull($dimFetchSlot, 'pipes[1] dim-fetch must lower');
        self::assertNotNull($falseSlot, 'hoisted false ConstFetch must lower');
        self::assertCount(2, $blockingSends, 'arg sends='.json_encode($blockingSends));
        self::assertSame($dimFetchSlot, $blockingSends[0], 'arg sends='.json_encode($blockingSends));
        self::assertSame($falseSlot, $blockingSends[1], 'arg sends='.json_encode($blockingSends));
        self::assertNotSame($blockingSends[0], $blockingSends[1], 'stream and mode must differ');
    }

    /** Issue #18186 — proc_get_status after proc_close reaches post-close TypeError once pipes unblock. */
    public function testProcGetStatusAfterCloseRuntime(): void
    {
        $code = <<<'PHP'
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('true', $desc, $pipes);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
while ('' !== (string) stream_get_contents($pipes[1]) || '' !== (string) stream_get_contents($pipes[2])) {
}
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
try {
    proc_get_status($proc);
    echo "no-throw\n";
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
echo 'closed=', $code, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'proc_get_status_after_close.php');

        ob_start();
        $runtime->run($block);
        self::assertSame("TypeError\nclosed=0\n", ob_get_clean());
    }

    /** Issue #15611 — get_defined_constants(true) assign must not steal firstSibling from get_declared_traits haystack. */
    public function testInArrayNestedGetDeclaredTraitsAfterGetDefinedConstantsRuntime(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void {
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

$c = get_defined_constants(true);
probe('declared_traits_has', in_array('Traversable', get_declared_traits(), true));

class CV { public static int $s = 1; }
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'get_declared_traits_after_defined_constants.php');

        $traitsReturnSlot = null;
        $inArrayHaystackSend = null;
        $fcallOrdinal = 0;
        $inArraySends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $traitsReturnSlot = null;
                }
                if (3 === $fcallOrdinal) {
                    $inArraySends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $traitsReturnSlot = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $inArraySends[] = $op->arg1;
            }
        }
        $inArrayHaystackSend = $inArraySends[1] ?? null;

        self::assertNotNull($traitsReturnSlot, 'get_declared_traits() must EXEC_RETURN before in_array haystack send');
        self::assertNotNull($inArrayHaystackSend);
        self::assertSame($traitsReturnSlot, $inArrayHaystackSend, 'in_array haystack must reuse get_declared_traits() slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('declared_traits_has: false', $out);
    }

    /** Issue #16253 / #16265 — strlen(); probe(..., in_array(..., g(), true)) wires in_array EXEC_RETURN, not haystack slot. */
    public function testInArrayStrictAfterVoidStmtCallUsesCalleeExecReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void {
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

strlen('probe');
probe('in_array_strict', in_array('stdClass', get_declared_classes(), true));
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_strict_after_strlen.php');

        $inArrayReturnSlot = null;
        $probeResultSend = null;
        $fcallOrdinal = 0;
        $probeSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (4 === $fcallOrdinal) {
                    $probeSends = [];
                }
            }
            // strlen, get_declared_classes(), in_array() — third INIT is nested in_array (#16265).
            if (3 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $inArrayReturnSlot = $op->arg1;
            }
            if (4 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $probeSends[] = $op->arg1;
            }
        }
        $probeResultSend = $probeSends[1] ?? null;

        self::assertNotNull($inArrayReturnSlot, 'in_array() must emit EXEC_RETURN before probe send');
        self::assertNotNull($probeResultSend);
        self::assertSame($inArrayReturnSlot, $probeResultSend, 'probe result must reuse in_array() EXEC_RETURN slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('in_array_strict: true', $out);
    }

    /** Issue #17882 — in_array(get_class($anon), get_declared_classes(), true) wires haystack EXEC_RETURN. */
    public function testInArrayGetClassGetDeclaredClassesHaystackExecReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

$anon = new class {
};

echo in_array(get_class($anon), get_declared_classes(), true) ? "yes\n" : "no\n";
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'get_declared_classes_nested_in_array.php');

        $declaredClassesReturnSlot = null;
        $inArrayHaystackSend = null;
        $fcallOrdinal = 0;
        $inArraySends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $inArraySends = [];
                }
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $declaredClassesReturnSlot = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $inArraySends[] = $op->arg1;
            }
        }
        $inArrayHaystackSend = $inArraySends[1] ?? null;

        self::assertNotNull($declaredClassesReturnSlot, 'get_declared_classes() must emit EXEC_RETURN');
        self::assertNotNull($inArrayHaystackSend);
        self::assertSame($declaredClassesReturnSlot, $inArrayHaystackSend, 'in_array haystack must reuse get_declared_classes() slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('yes', $out);
    }

    /** Issue #16312 — stmt follow-on probe() must not clobber prior in_array EXEC_RETURN wiring. */
    public function testInArrayStrictAfterVoidStmtCallWithFollowOnProbe(): void
    {
        $code = file_get_contents(
            __DIR__.'/../repro/maintainer_gap_in_array_strict_null_after_call.php'
        );

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_strict_follow_on_probe.php');

        $inArrayReturnSlot = null;
        $probeResultSend = null;
        $fcallOrdinal = 0;
        $probeSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (4 === $fcallOrdinal) {
                    $probeSends = [];
                }
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $inArrayReturnSlot = $op->arg1;
            }
            if (4 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $probeSends[] = $op->arg1;
            }
        }
        $probeResultSend = $probeSends[1] ?? null;

        self::assertNotNull($inArrayReturnSlot);
        self::assertSame($inArrayReturnSlot, $probeResultSend);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('in_array_strict: true', $out);
        self::assertStringContainsString('array_search_strict: false', $out);
    }

    /** Issue #17000 — in_array dim-fetch haystack after echo ternary chain must not reuse concat slot. */
    public function testInArrayDimFetchHaystackAfterEchoTernaryChain(): void
    {
        $code = file_get_contents(
            __DIR__.'/../repro/maintainer_gap_get_defined_functions_in_array_haystack.php'
        );
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'get_defined_functions_in_array_haystack.php');

        ob_start();
        $runtime->run($block);
        self::assertSame("11110\n", ob_get_clean());
    }

    /** Issue #17980 — in_array()/array_search() haystack $arr['k'] ?? [] inline uses coalesce merge slot. */
    public function testInArrayCoalesceHaystackInlineCallArgUsesCoalesceMergeSlot(): void
    {
        $code = file_get_contents(
            __DIR__.'/../repro/maintainer_gap_in_array_coalesce_haystack_call_arg.php'
        );
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_coalesce_haystack_call_arg.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('bool(true)', $out);
        self::assertStringContainsString('int(1)', $out);
    }

    /** Issue #10303 — tempnam(sys_get_temp_dir(), E::A) wires enum case fetch to arg #1. */
    public function testTempnamNestedFuncCallEnumPrefixUsesClassConstFetchSlot(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'x'; }
tempnam(sys_get_temp_dir(), E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'tempnam_enum_prefix.php');

        $enumFetchSlot = null;
        $sysTempReturnSlot = null;
        $argSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                $enumFetchSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null === $sysTempReturnSlot) {
                $sysTempReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $argSends[] = $op->arg1;
            }
        }
        $tempnamSends = \array_slice($argSends, -2);

        self::assertNotNull($enumFetchSlot);
        self::assertNotNull($sysTempReturnSlot);
        self::assertSame($sysTempReturnSlot, $tempnamSends[0] ?? null);
        self::assertSame($enumFetchSlot, $tempnamSends[1] ?? null);
    }

    /** Issue #9611 — flock(fopen(...), LOCK_EX) nested inline call runtime parity with Zend. */
    public function testFlockNestedFopenLockExRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_probe_flock_flags.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'flock_nested_fopen_lock_ex.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("true\ntrue\nok\n", ob_get_clean());
    }

    /** Issue #15931 — var_dump(...); ini_get_all(null, false) wires ConstFetch slots, not prior dump return. */
    public function testIniGetAllAfterVarDumpUsesConstFetchLiteralSlots(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_ini_get_all_standard.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ini_get_all_after_var_dump.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('flat string', $out);
        self::assertStringNotContainsString('details flag must be a boolean', $out);
    }

    /** Issue #16241 — unserialize(serialize($obj)) wires serialize EXEC_RETURN, not New_ object slot. */
    public function testUnserializeSerializeNestedUsesInnerFuncCallReturnSlot(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_16241_nested_serialize.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_16241_nested_serialize.php');

        $serializeReturnSlot = null;
        $unserializeSendSlot = null;
        $newSlot = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newSlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $serializeReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $unserializeSendSlot = $op->arg1;
            }
        }

        self::assertNotNull($serializeReturnSlot);
        self::assertNotNull($unserializeSendSlot);
        self::assertNotSame($newSlot, $unserializeSendSlot, 'must not wire object New_ slot to unserialize');
        self::assertSame($serializeReturnSlot, $unserializeSendSlot);

        ob_start();
        $runtime->run($block);
        self::assertSame("42\n", ob_get_clean());
    }

    /** Issue #16241 — unserialize(serialize($obj)) runtime parity with Zend (php-src ext/standard/var.c). */
    public function testUnserializeSerializeNestedRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_serialize_unserialize_magic.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_serialize_unserialize_magic.php');

        ob_start();
        $runtime->run($block);
        self::assertSame("serialize_unserialize_magic_ok\n", ob_get_clean());
    }

    /** Issue #16273 — assign-in-call must wire pack() RHS when php-cfg dead arg ≠ assign.result (#11365). */
    public function testStrlenAssignExprPackCallArgUsesPackReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
echo strlen(($q = pack('C', 0))), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'strlen_assign_expr_pack.php');

        $packReturnSlot = null;
        $strlenArgSlot = null;
        $pendingInit = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $name = $block->constants[$op->arg1]->toString();
                $pendingInit = $name;
                if ('pack' === $name) {
                    $packReturnSlot = null;
                }
                if ('strlen' === $name) {
                    $strlenArgSlot = null;
                }
                continue;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && 'pack' === $pendingInit) {
                $packReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type && 'strlen' === $pendingInit) {
                $strlenArgSlot = $op->arg1;
            }
        }

        self::assertNotNull($packReturnSlot, 'pack return slot missing');
        self::assertNotNull($strlenArgSlot, 'strlen arg send missing');
        self::assertSame($packReturnSlot, $strlenArgSlot, 'strlen must consume pack() return slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("1\n", ob_get_clean());
    }

    /** Issue #16331 — method_exists($rA) must not clobber named ReflectionEnumUnitCase CV. */
    public function testMethodExistsJumpIfPreservesReflectionEnumUnitCaseNamedLocal(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = file_get_contents(__DIR__ . '/../repro/maintainer_gap_reflection_enum_unit_case_isdeprecated.php');
            self::assertIsString($code);
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'reflection_enum_unit_case_isdeprecated.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("bool(true)\nbool(true)\nbool(false)\ndeprecated\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #10177 — sequential setlocale(LC_ALL, …) must not steal prior EXEC_RETURN for LC_ALL prelude. */
    public function testSequentialSetlocaleQueryNullKeepsHoistedLcAllPreludeSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$q1 = setlocale(LC_ALL, null);
setlocale(LC_ALL, 'C');
$q2 = setlocale(LC_ALL, null);
echo $q1, ':', $q2, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'setlocale_query_null_chain.php');

        $returnSlots = [];
        $thirdCallSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (3 === $fcallOrdinal) {
                    $thirdCallSends = [];
                }
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $returnSlots[] = $op->arg1;
            }
            if (3 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $thirdCallSends[] = $op->arg1;
            }
        }

        self::assertCount(2, $thirdCallSends);
        self::assertNotContains(
            $thirdCallSends[0],
            $returnSlots,
            'third setlocale category must not use prior EXEC_RETURN; sends='.json_encode($thirdCallSends)
                .' returns='.json_encode($returnSlots)
        );

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertMatchesRegularExpression('/^[^:]+:.*\n$/', $out);
        [$first, $second] = explode(':', trim($out));
        self::assertSame($first, $second);
    }

    /** Issue #10174 — new ArrayObject([...], C::FLAGS) must not wire ClassConst prelude to array arg #0. */
    public function testArrayObjectConstructClassConstFlagsArgRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_arrayobject_array_as_props.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_arrayobject_array_as_props.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("q\nq\nv\n", $out);
    }

    /** Issue #10474 — is_array(file(..., FILE_* | FILE_*)) must wire file() result, not bitmask OR slot. */
    public function testIsArrayFileConstFlagsArgSendUsesFuncCallExecReturn(): void
    {
        $repro = __DIR__.'/../repro/maintainer_file_named_flags.php';
        $code = file_get_contents($repro);
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, $repro);

        $bitwiseOrSlots = [];
        $consumerPairs = [];
        $seen = new \SplObjectStorage();
        $walk = function (Block $compileBlock) use (&$walk, &$seen, &$bitwiseOrSlots, &$consumerPairs): void {
            if ($seen->contains($compileBlock)) {
                return;
            }
            $seen[$compileBlock] = true;
            foreach ($compileBlock->opCodes as $op) {
                if (OpCode::TYPE_BITWISE_OR === $op->type) {
                    $bitwiseOrSlots[] = $op->arg1;
                }
            }
            foreach ($compileBlock->opCodes as $i => $op) {
                if (OpCode::TYPE_FUNCCALL_INIT !== $op->type) {
                    continue;
                }
                $name = $compileBlock->constants[$op->arg1]->toString();
                if (!\in_array($name, ['is_array', 'count'], true)) {
                    continue;
                }
                $fileExec = null;
                for ($j = $i - 1; $j >= 0; --$j) {
                    $prior = $compileBlock->opCodes[$j];
                    if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $prior->type) {
                        $fileExec = $prior->arg1;
                        break;
                    }
                }
                if (null === $fileExec) {
                    continue;
                }
                $sendSlot = $compileBlock->opCodes[$i + 1]->arg1 ?? null;
                if (OpCode::TYPE_ARG_SEND === ($compileBlock->opCodes[$i + 1]->type ?? null)) {
                    $consumerPairs[$name][] = [$fileExec, $sendSlot];
                }
            }
            foreach ($compileBlock->opCodes as $op) {
                if (null !== $op->block1) {
                    $walk($op->block1);
                }
                if (null !== $op->block2) {
                    $walk($op->block2);
                }
            }
        };
        $walk($block);

        self::assertNotEmpty($bitwiseOrSlots, 'expected FILE_* | FILE_* bitwise OR slot');
        foreach ($consumerPairs['is_array'] ?? [] as $pair) {
            self::assertSame($pair[0], $pair[1], 'is_array must use adjacent file() exec return');
            self::assertNotContains($pair[1], $bitwiseOrSlots, 'is_array must not use bitmask OR slot');
        }
        foreach ($consumerPairs['count'] ?? [] as $pair) {
            self::assertSame($pair[0], $pair[1], 'count must use adjacent file() exec return');
            self::assertNotContains($pair[1], $bitwiseOrSlots, 'count must not use bitmask OR slot');
        }

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('const=array', $out);
        self::assertStringContainsString('ternary=10', $out);
    }

    /** Issue #16802 — count($proc) === count($all) must not reuse prior echo count() EXEC_RETURN. */
    public function testCountIdenticalAfterStaticListIdentifiersUsesNamedLocals(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$all = DateTimeZone::listIdentifiers();
$us = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
echo count($us), "\n";
$proc = timezone_identifiers_list();
echo count($proc) === count($all) ? "proc_sync\n" : "proc_mismatch\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'timezone_count_identical.php');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("29\n", $out);
        self::assertStringContainsString('proc_sync', $out);
    }

    /** Issue #17767 — var_export($obj->m(), true) in concat must wire method EXEC_RETURN, not spurious echo. */
    public function testMethodCallFalseReturnVarExportConcatOperandSlotWiring(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class C { public function f(): bool { return false; } }
$c = new C();
echo 'x='.var_export($c->f(), true)."\n";
function gen(): Generator { yield 1; return 99; }
$g = gen(); $g->next(); $g->next();
echo 'valid='.var_export($g->valid(), true)."\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'method_call_concat_operand.php');

        $methodReturnSlot = null;
        $varExportArgSend = null;
        $pendingMethod = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                $pendingMethod = $block->constants[$op->arg2]->toString();
                continue;
            }
            if ('f' === $pendingMethod && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $methodReturnSlot = $op->arg1;
                $pendingMethod = null;
                continue;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                $name = $block->constants[$op->arg1]->toString();
                if (1 === $fcallOrdinal && 'var_export' === $name) {
                    continue;
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $varExportArgSend) {
                $varExportArgSend = $op->arg1;
            }
        }

        self::assertNotNull($methodReturnSlot, 'method f() must EXEC_RETURN before var_export');
        self::assertNotNull($varExportArgSend, 'var_export must ARG_SEND method return');
        self::assertSame($methodReturnSlot, $varExportArgSend, 'var_export arg0 must reuse method return slot');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("x=false\nvalid=false\n", $out);
    }

    /** Issue #17846 — touch(); ob_start(fn(...)) must wire closure slot, not stmt-level touch producer. */
    public function testObStartClosureAfterTouchRuntime(): void
    {
        $code = <<<'PHP'
<?php
$p = sys_get_temp_dir() . '/phpc_ob_touch_' . getmypid() . '.tmp';
touch($p, 1);
ob_start(fn($b) => strtoupper($b));
echo 'hi';
ob_end_flush();
@unlink($p);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ob_start_closure_after_touch.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('HI', ob_get_clean());
    }

    /** Issue #17989 — array_walk($a, fn) after ob_start() must ARG_SEND named CV, not ob_start return. */
    public function testArrayWalkNamedObjectCastAfterObStartUsesCvSlot(): void
    {
        $code = <<<'PHP'
<?php
$a = (object) ['x' => 1];
ob_start();
array_walk($a, static fn ($v) => print($v));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_walk_object_cast_after_ob_start.php');

        $obStartReturnSlot = null;
        $arrayWalkHaystackSend = null;
        $pendingFunc = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $pendingFunc = $block->constants[$op->arg1]->toString();
                continue;
            }
            if ('ob_start' === $pendingFunc && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $obStartReturnSlot = $op->arg1;
                $pendingFunc = null;
                continue;
            }
            if ('array_walk' === $pendingFunc && OpCode::TYPE_ARG_SEND === $op->type && null === $arrayWalkHaystackSend) {
                $arrayWalkHaystackSend = $op->arg1;
                $pendingFunc = null;
            }
        }

        self::assertNotNull($obStartReturnSlot, 'ob_start return slot');
        self::assertNotNull($arrayWalkHaystackSend, 'array_walk haystack ARG_SEND');
        self::assertNotSame($obStartReturnSlot, $arrayWalkHaystackSend, 'array_walk arg0 must not be ob_start() return');

        ob_start();
        $runtime->run($block);
        self::assertSame('1', ob_get_clean());
    }

    /** Bootstrap spine — foreach assign-in-call with literal RHS must not read Operand::result (#1492). */
    public function testCurlFileBuiltinRegisterCompilesWithoutAssignInCallRhsOperandCrash(): void
    {
        $path = realpath(__DIR__.'/../../ext/curl/CurlFileBuiltin.php');
        self::assertNotFalse($path);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompileFile($path);
        self::assertNotNull($block);
    }

    /** Issue #8866 — unpack('i', pack('i', 1), E::A) wires pack EXEC_RETURN to arg #1, enum to arg #2. */
    public function testUnpackInlinePackEnumOffsetCallArgSlots(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 5; }
try {
    unpack('i', pack('i', 1), E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile($code, 'unpack_inline_pack_enum.php'));
        $out = ob_get_clean();
        self::assertSame(
            'TypeError: unpack(): Argument #3 ($offset) must be of type int, E given',
            trim($out)
        );
    }

    /** Issue #23893 — bare local as call arg inside function matches Zend. */
    public function testBareLocalCallArgUsesNamedAssignDestSlot(): void
    {
        $code = <<<'PHP'
<?php
function show_local() {
  $x = 42;
  var_dump($x);
}
show_local();
function pass_to_user() {
  $x = 7;
  sink($x);
}
function sink($v) { var_dump($v); }
pass_to_user();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'bare_local_call_arg.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("int(42)\nint(7)\n", ob_get_clean());
    }

    /**
     * Issue #26458 — appendChild then insertBefore($n, null) must keep prior siblings
     * (literal null ≡ append; #25702 ConstFetch-null hoist must not drop the append).
     */
    public function testDomInsertBeforeLiteralNullAfterAppendChildPreservesSiblings(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$r->insertBefore($b, null);
echo $r->childNodes->length, ' ', $r->C14N(), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dom_insertbefore_literal_null_26458.php');

        $sawAppendChildForA = false;
        $sawInsertBefore = false;
        $afterCreateA = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_SEND === $op->type
                && null !== $op->arg1
                && isset($block->constants[$op->arg1])
                && 'a' === $block->constants[$op->arg1]->toString()
            ) {
                $afterCreateA = true;
            }
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type || null === $op->arg2) {
                continue;
            }
            if (!isset($block->constants[$op->arg2])) {
                continue;
            }
            $name = $block->constants[$op->arg2]->toString();
            if ($afterCreateA && 'appendChild' === $name) {
                $sawAppendChildForA = true;
            }
            if ('insertBefore' === $name) {
                $sawInsertBefore = true;
            }
        }
        self::assertTrue($sawAppendChildForA, 'appendChild($a) METHODCALL_INIT must remain before insertBefore($b, null)');
        self::assertTrue($sawInsertBefore, 'insertBefore METHODCALL_INIT missing');

        ob_start();
        $runtime->run($block);
        self::assertSame("2 <root><a></a><b></b></root>\n", ob_get_clean());
    }

}

