<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_getcpuaffinity) — #20510. */
final class pcntl_getcpuaffinity extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_getcpuaffinity');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'pcntl_getcpuaffinity() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = null;
        if (1 === $argc) {
            $pidArg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pidArg->type) {
                $pid = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_getcpuaffinity', 0, 'process_id');
            }
        }
        $cpus = VmPcntl::getcpuaffinity($pid);
        if (false === $cpus) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        $idx = 0;
        foreach ($cpus as $cpu) {
            $var = new Variable();
            $var->int((int) $cpu);
            $ht->addIndex($idx, $var);
            ++$idx;
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_getcpuaffinity() is not implemented for JIT in this compiler build (issue #20510)');
    }
}
