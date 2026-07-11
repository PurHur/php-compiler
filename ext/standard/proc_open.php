<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * proc_open() — subprocess pipes (php-src ext/standard/proc_open.c; #3131, #6904).
 *
 * VM: {@see VmProcess::procOpen()} via {@see VmProcessProcOpenNative} when FFI available; JIT/AOT: __compiler_proc_open (string command + pipe spec v1).
 */
final class proc_open extends Internal
{
    public function __construct()
    {
        parent::__construct('proc_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 6) {
            throw new \LogicException('proc_open() requires three to six arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $command = self::parseCommand($frame->calledArgs[0], 'proc_open', 1);
        $descriptorSpec = self::parseDescriptorSpec($frame->calledArgs[1], 'proc_open', 2);
        $cwd = null;
        if (\array_key_exists(3, $frame->calledArgs)) {
            $cwdVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $cwdVar->type) {
                $cwd = VmString::coerceStringBuiltinArg($cwdVar, 'proc_open', 4, 'cwd');
            }
        }
        $env = null;
        if (\array_key_exists(4, $frame->calledArgs)) {
            $env = self::parseEnv($frame->calledArgs[4], 'proc_open', 5);
        }

        $result = VmProcess::procOpen($command, $descriptorSpec, $cwd, $env);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        [$procId, $pipeHandles] = $result;

        $pipesVar = $frame->calledArgs[2]->resolveIndirect();
        $pipesHt = new HashTable();
        foreach ($pipeHandles as $fd => $handleId) {
            $slot = new Variable();
            $slot->streamHandle($handleId, $frame->vmContext);
            $pipesHt->addIndex($fd, $slot);
        }
        $pipesOut = new Variable();
        $pipesOut->array($pipesHt);
        $pipesVar->copyFrom($pipesOut);

        $frame->returnVar->processHandle($procId, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitProcOpen::invoke($context, ...$args);
    }

    /** @return string|array */
    private static function parseCommand(Variable $arg, string $functionName, int $argNum): string|array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $command = $arg->toString();
            VmString::rejectEmptyBuiltinStringArg($command, $functionName, $argNum - 1, 'command');

            return $command;
        }
        if (Variable::TYPE_ARRAY === $arg->type) {
            $parts = [];
            foreach ($arg->toArray()->iterateKeyed(true) as $pair) {
                [, $partVar] = $pair;
                $parts[] = VmString::coerceStringBuiltinArg(
                    $partVar->resolveIndirect(),
                    $functionName,
                    $argNum,
                    'command'
                );
            }
            if ([] === $parts) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($command) must have at least one element',
                    $functionName,
                    $argNum
                ));
            }

            return $parts;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($command) must be of type array|string, %s given',
            $functionName,
            $argNum,
            VmStreamArg::debugTypeName($arg)
        ));
    }

    /**
     * @return array<int, array{0: string, 1?: string}>
     */
    private static function parseDescriptorSpec(Variable $arg, string $functionName, int $argNum): array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($descriptor_spec) must be of type array, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($arg)
            ));
        }
        $spec = [];
        foreach ($arg->toArray()->iterateKeyed(true) as $pair) {
            [$keyVar, $entryVar] = $pair;
            $fd = $keyVar->resolveIndirect()->toInt();
            $entryVar = $entryVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $entryVar->type) {
                throw new \ValueError(\sprintf(
                    '%s(): supplied descriptor spec is not valid',
                    $functionName
                ));
            }
            $cells = [];
            foreach ($entryVar->toArray()->iterateKeyed(true) as $cell) {
                [, $cellVar] = $cell;
                $cells[] = VmString::coerceStringBuiltinArg(
                    $cellVar->resolveIndirect(),
                    $functionName,
                    $argNum,
                    'descriptor_spec'
                );
            }
            if ([] === $cells || 'pipe' !== $cells[0]) {
                throw new \ValueError(\sprintf(
                    '%s(): supplied descriptor spec is not valid',
                    $functionName
                ));
            }
            $spec[$fd] = $cells;
        }

        return $spec;
    }

    /** @return array<string, string>|null */
    private static function parseEnv(Variable $arg, string $functionName, int $argNum): ?array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($env_vars) must be of type array|null, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($arg)
            ));
        }
        $env = [];
        foreach ($arg->toArray()->iterateKeyed(true) as $pair) {
            [$keyVar, $valVar] = $pair;
            $env[VmString::coerceStringBuiltinArg($keyVar->resolveIndirect(), $functionName, $argNum, 'env_vars')] =
                VmString::coerceStringBuiltinArg($valVar->resolveIndirect(), $functionName, $argNum, 'env_vars');
        }

        return $env;
    }
}
