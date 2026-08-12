<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * exec() — run external program (php-src ext/standard/exec.c; #3278).
 *
 * VM: {@see VmExecNative}; JIT/AOT: {@see JitExec} / ProcessRuntime (#8640, phase 2 #3278).
 */
final class exec extends Internal
{
    public function __construct()
    {
        parent::__construct('exec');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('exec() accepts one to three arguments in this compiler build');
        }
        $command = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'exec', 'command', false);
        // php-src exec.c — zend_argument_must_not_be_empty_error → Zend "cannot be empty" (#30340)
        VmString::rejectEmptyBuiltinStringArg($command, 'exec', 0, 'command', true);
        $result = VmExecNative::run($command);
        if (false !== $result && $argc >= 2) {
            $ht = new HashTable();
            foreach ($result['lines'] as $line) {
                $var = new Variable();
                $var->string($line);
                $ht->append($var);
            }
            $frame->calledArgs[1]->resolveIndirect()->array($ht);
        }
        if (false !== $result && $argc >= 3) {
            $frame->calledArgs[2]->resolveIndirect()->int($result['status']);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $lines = $result['lines'];
        if ([] === $lines) {
            $frame->returnVar->string('');

            return;
        }
        $frame->returnVar->string($lines[\count($lines) - 1]);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitExec::exec($context, ...$args);
    }
}
