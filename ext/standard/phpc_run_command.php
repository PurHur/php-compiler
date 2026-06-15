<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * phpc_run_command() — capture exit code + stdout/stderr for AOT linker (#2779).
 *
 * Replaces proc_open()/stream_get_contents() in lib/AOT/Linker.php for native self-host.
 *
 * VM: {@see VmPhpcRunCommandNative} (popen when env null; fork/pipe when env set, #8648).
 */
final class phpc_run_command extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_run_command');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('phpc_run_command() requires one or two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $cmdVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $cmdVar->type) {
            throw new \LogicException('phpc_run_command() command must be a string');
        }
        $env = null;
        if (2 === $argc) {
            $envVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $envVar->type) {
                throw new \LogicException('phpc_run_command() env must be an array');
            }
            $env = [];
            foreach ($envVar->toArray()->iterateKeyed(true) as $pair) {
                [$keyVar, $valVar] = $pair;
                if (Variable::TYPE_STRING !== $keyVar->type || Variable::TYPE_STRING !== $valVar->type) {
                    continue;
                }
                $env[$keyVar->toString()] = $valVar->toString();
            }
        }
        $captured = VmPhpcRunCommandNative::run($cmdVar->toString(), $env);
        if (null === $captured) {
            $frame->returnVar->null();

            return;
        }
        $ht = new HashTable();
        $codeVar = new Variable();
        $codeVar->int($captured['code']);
        $ht->add('code', $codeVar);
        $stdoutVar = new Variable();
        $stdoutVar->string($captured['stdout']);
        $ht->add('stdout', $stdoutVar);
        $stderrVar = new Variable();
        $stderrVar->string($captured['stderr']);
        $ht->add('stderr', $stderrVar);
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('phpc_run_command() requires one or two arguments');
        }

        $envArg = 2 === \count($args) ? $args[1] : null;

        return JitPhpcRunCommand::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'phpc_run_command() command'),
            $envArg
        );
    }
}
