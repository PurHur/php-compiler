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

    /** Issue #9389 — proc_open array argv + null cwd + env array inline preludes map to distinct arg slots. */
    public function testProcOpenArrayArgvNullCwdEnvInlinePreludesSendDistinctSlots(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
proc_open(['printenv', 'VAR'], $desc, $pipes, null, ['VAR' => 'expected']);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'proc_open_argv_env.php');
        self::assertNotNull($block);

        $commandArraySlot = null;
        $nullSlot = null;
        $envArraySlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg2) {
                $const = $block->constants[$op->arg2] ?? null;
                if (null !== $const && 'printenv' === $const->toString()) {
                    $commandArraySlot = $op->arg1;
                }
                if (null !== $const && 'expected' === $const->toString()) {
                    $envArraySlot = $op->arg1;
                }
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type && null !== $op->arg2) {
                $const = $block->constants[$op->arg2] ?? null;
                if (null !== $const && 'null' === $const->toString()) {
                    $nullSlot = $op->arg1;
                }
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        self::assertNotNull($commandArraySlot, 'command array slot');
        self::assertNotNull($nullSlot, 'null cwd slot');
        self::assertNotNull($envArraySlot, 'env array slot');
        self::assertGreaterThanOrEqual(5, \count($sendSlots), 'proc_open sends five args');
        self::assertSame($commandArraySlot, $sendSlots[0], 'arg #0 command array');
        self::assertSame($nullSlot, $sendSlots[3], 'arg #3 null cwd');
        self::assertSame($envArraySlot, $sendSlots[4], 'arg #4 env array');
        self::assertNotSame($sendSlots[0], $sendSlots[4], 'command and env must not alias');
    }

    /** Issue #11485 — inline nested descriptor_spec must ARG_SEND outer array slot, not inner pipe tuple. */
    public function testProcOpenInlineDescriptorSpecSendsOutermostArraySlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$pipes = [];
proc_open('true', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'proc_open_inline_descriptor.php');
        self::assertNotNull($block);

        $innerPipeSlot = null;
        $sendSlots = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg2) {
                $const = $block->constants[$op->arg2] ?? null;
                if (null !== $const && 'pipe' === $const->toString()) {
                    $innerPipeSlot = $op->arg1;
                }
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $sendSlots[] = $op->arg1;
            }
        }
        self::assertNotNull($innerPipeSlot, 'inner pipe tuple slot');
        self::assertGreaterThanOrEqual(3, \count($sendSlots), 'proc_open sends three args');
        self::assertNotSame($innerPipeSlot, $sendSlots[1], 'descriptor_spec must not send inner pipe tuple slot');

        $outerDescriptorSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY !== $op->type || null === $op->arg2 || null === $op->arg3) {
                continue;
            }
            $keyConst = $block->constants[$op->arg3] ?? null;
            if (null !== $keyConst && '0' === $keyConst->toString()) {
                $outerDescriptorSlot = $op->arg1;
                break;
            }
        }
        self::assertNotNull($outerDescriptorSlot, 'outer descriptor array slot');
        self::assertSame($outerDescriptorSlot, $sendSlots[1], 'descriptor_spec must send outer array slot');
    }
}
