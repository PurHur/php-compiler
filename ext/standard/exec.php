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
        // php-src ext/standard/exec.c / basic_functions.stub.php — ArgumentCountError (#30566)
        $this->requireArgCountRange($frame, 'exec', 1, 3);
        $command = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'exec', 'command', false);
        // php-src exec.c — zend_argument_must_not_be_empty_error (#30340 / #30625)
        VmString::rejectEmptyBuiltinStringArg($command, 'exec', 0, 'command', true);
        $result = VmExecNative::run($command);
        // Named result_code: skips optional output — php-src ZEND_PARSE_PARAMETERS hole (#23625).
        if (false !== $result && isset($frame->calledArgs[1])) {
            $ht = new HashTable();
            foreach ($result['lines'] as $line) {
                $var = new Variable();
                $var->string($line);
                $ht->append($var);
            }
            $frame->calledArgs[1]->resolveIndirect()->array($ht);
        }
        if (false !== $result && isset($frame->calledArgs[2])) {
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
