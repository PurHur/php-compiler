<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #28891 — nested FuncCall arg with sibling inline Array_ literal.
 *
 * in_array(id('x'), ['x'], true) must EXEC_RETURN id into the needle slot;
 * array_merge(['a'], id(['b'])) must send id's array, not the outer ['a'].
 */
final class NestedFuncCallArrayLiteralArgTest extends TestCase
{
    public function testInArrayNestedIdWithArrayLiteralHaystack(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function id(mixed $x): mixed { return $x; }
var_export(in_array(id('x'), ['x'], true));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_funcall_in_array.php');

        $idReturnSlot = null;
        $inArrayNeedleSend = null;
        $sawIdExecReturn = false;
        $fcallOrdinal = 0;
        $currentSends = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if (3 === \count($currentSends) && null === $inArrayNeedleSend) {
                    $inArrayNeedleSend = $currentSends[0];
                }
                ++$fcallOrdinal;
                $currentSends = [];
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $sawIdExecReturn = true;
                $idReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $currentSends[] = $op->arg1;
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                self::fail('id() must EXEC_RETURN when feeding in_array needle (#28891)');
            }
        }
        if (3 === \count($currentSends) && null === $inArrayNeedleSend) {
            $inArrayNeedleSend = $currentSends[0];
        }

        self::assertTrue($sawIdExecReturn, 'expected id() FUNCCALL_EXEC_RETURN');
        self::assertNotNull($idReturnSlot, 'id() return slot');
        self::assertSame($idReturnSlot, $inArrayNeedleSend, 'in_array needle must use id() return slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("true\n", ob_get_clean());
    }

    public function testArrayMergeOuterArrayAndNestedIdArray(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function id(mixed $x): mixed { return $x; }
var_export(array_merge(['a'], id(['b'])));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nested_funcall_array_merge.php');

        $idArgSend = null;
        $idReturnSlot = null;
        $mergeSends = [];
        $fcallOrdinal = 0;
        $currentSends = [];
        $initArraysBeforeId = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && 0 === $fcallOrdinal) {
                $initArraysBeforeId[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if (1 === $fcallOrdinal && 1 === \count($currentSends)) {
                    $idArgSend = $currentSends[0];
                }
                if (2 === $fcallOrdinal && 2 === \count($currentSends)) {
                    $mergeSends = $currentSends;
                }
                ++$fcallOrdinal;
                $currentSends = [];
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $idReturnSlot = $op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $currentSends[] = $op->arg1;
            }
        }
        if (1 === \count($currentSends) && null === $idArgSend && 1 === $fcallOrdinal) {
            $idArgSend = $currentSends[0];
        }
        if (2 === \count($currentSends) && [] === $mergeSends) {
            $mergeSends = $currentSends;
        }

        self::assertCount(2, $initArraysBeforeId, 'expected outer + nested INIT_ARRAY before id()');
        self::assertNotNull($idArgSend, 'id() ARG_SEND missing');
        self::assertSame(
            $initArraysBeforeId[1],
            $idArgSend,
            'id() must receive nested [\'b\'] slot, not outer [\'a\']'
        );
        self::assertNotSame($initArraysBeforeId[0], $idArgSend);
        self::assertNotNull($idReturnSlot, 'id() return slot');
        self::assertCount(2, $mergeSends, 'array_merge arg sends=' . json_encode($mergeSends));
        self::assertSame($idReturnSlot, $mergeSends[1], 'array_merge arg #1 must use id() return');

        ob_start();
        $runtime->run($block);
        self::assertSame("array (\n  0 => 'a',\n  1 => 'b',\n)\n", ob_get_clean());
    }

    public function testFullReproScriptMatchesZend(): void
    {
        $path = __DIR__ . '/../repro/maintainer_gap_nested_funcall_array_literal_arg.php';
        self::assertFileExists($path);
        $code = file_get_contents($path);
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_nested_funcall_array_literal_arg.php');
        ob_start();
        $runtime->run($block);
        $vm = ob_get_clean();

        ob_start();
        require $path;
        $zend = ob_get_clean();

        self::assertSame($zend, $vm);
    }
}