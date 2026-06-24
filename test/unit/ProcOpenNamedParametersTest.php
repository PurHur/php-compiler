<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group compliance */
final class ProcOpenNamedParametersTest extends TestCase
{
    public function testProcOpenNamedArrayCommandSendsCompiledArraySlot(): void
    {
        $code = file_get_contents(__DIR__.'/../repro-maintainer/parity_proc_open_named_parameters.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'proc_open_named.php');
        self::assertNotNull($block);

        $commandSendSlot = null;
        $echoArraySlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg2) {
                $const = $block->constants[$op->arg2] ?? null;
                if (null !== $const && 'echo' === $const->toString()) {
                    $echoArraySlot = $op->arg1;
                }
            }
            if (
                OpCode::TYPE_ARG_SEND === $op->type
                && null !== $op->arg2
                && isset($block->constants[$op->arg2])
                && 'command' === $block->constants[$op->arg2]->toString()
            ) {
                $commandSendSlot = $op->arg1;
            }
        }
        self::assertNotNull($echoArraySlot, 'command array literal slot');
        self::assertNotNull($commandSendSlot, 'command named send');
        self::assertSame($echoArraySlot, $commandSendSlot, 'command: named arg must send compiled array slot');
    }
}
