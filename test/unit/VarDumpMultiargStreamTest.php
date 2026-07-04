<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** var_dump($stream, gettype($stream)) must not misbind hoisted gettype return (#11144). */
final class VarDumpMultiargStreamTest extends TestCase
{
    public function testVarDumpStreamAndGettypeUseDistinctArgSendSlots(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_var_dump_multiarg_stream.php');
        self::assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_dump_multiarg_stream.php');

        $execReturns = [];
        $varDumpSends = [];
        $returnCount = 0;
        $capture = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                ++$returnCount;
                $execReturns[] = $op->arg1;
                if (2 === $returnCount) {
                    $capture = true;
                }
                continue;
            }
            if ($capture && OpCode::TYPE_ARG_SEND === $op->type) {
                $varDumpSends[] = $op->arg1;
                if (2 === \count($varDumpSends)) {
                    break;
                }
            }
        }

        self::assertCount(2, $execReturns, 'expected fopen + gettype EXEC_RETURN');
        self::assertCount(2, $varDumpSends, 'var_dump ARG_SEND count');
        self::assertNotSame($varDumpSends[0], $varDumpSends[1], 'var_dump args must use distinct slots');
        self::assertSame($execReturns[1], $varDumpSends[1], 'var_dump arg #2 must be gettype EXEC_RETURN');
    }
}
