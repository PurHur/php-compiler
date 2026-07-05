<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** var_dump(strlen($s), substr($s, 0, 2)) must not misbind sibling EXEC_RETURN slots (#16254). */
final class VarDumpMultiargBuiltinMisbindTest extends TestCase
{
    public function testVarDumpStrlenSubstrUseDistinctArgSendSlots(): void
    {
        $code = <<<'PHP'
<?php
$s = 'hello';
var_dump(strlen($s), substr($s, 0, 2));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_dump_multiarg_builtin.php');

        $execReturns = [];
        $varDumpSends = [];
        $capture = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $execReturns[] = $op->arg1;
                continue;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type && 4 === (int) $op->arg1) {
                $capture = true;
                continue;
            }
            if ($capture && OpCode::TYPE_ARG_SEND === $op->type) {
                $varDumpSends[] = $op->arg1;
                if (2 === \count($varDumpSends)) {
                    break;
                }
            }
        }

        self::assertCount(2, $execReturns, 'expected strlen + substr EXEC_RETURN');
        self::assertCount(2, $varDumpSends, 'var_dump ARG_SEND count');
        self::assertNotSame($varDumpSends[0], $varDumpSends[1], 'var_dump args must use distinct slots');
        self::assertSame($execReturns[0], $varDumpSends[0], 'var_dump arg #1 must be strlen EXEC_RETURN');
        self::assertSame($execReturns[1], $varDumpSends[1], 'var_dump arg #2 must be substr EXEC_RETURN');
    }

    public function testFullReproSecondVarDumpFtellFgetcUseDistinctSlots(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_var_dump_multiarg_builtin_misbind.php');
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_dump_multiarg_full.php');

        $execReturns = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $execReturns[] = $op->arg1;
            }
        }

        self::assertGreaterThanOrEqual(6, \count($execReturns), 'expected ftell + fgetc EXEC_RETURN after first pair');

        $varDumpSends = [];
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                continue;
            }
            if (OpCode::TYPE_RETURN === $op->type) {
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                array_unshift($varDumpSends, $op->arg1);
                if (2 === \count($varDumpSends)) {
                    break;
                }
            }
        }

        $ftellReturn = $execReturns[4];
        $fgetcReturn = $execReturns[5];
        self::assertCount(2, $varDumpSends, 'second var_dump ARG_SEND count');
        self::assertNotSame($varDumpSends[0], $varDumpSends[1], 'second var_dump args must use distinct slots');
        self::assertSame($ftellReturn, $varDumpSends[0], 'second var_dump arg #1 must be ftell EXEC_RETURN');
        self::assertSame($fgetcReturn, $varDumpSends[1], 'second var_dump arg #2 must be fgetc EXEC_RETURN');
    }
}
