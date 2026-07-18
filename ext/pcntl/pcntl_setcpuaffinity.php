<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmArray;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_setcpuaffinity) — #20510. */
final class pcntl_setcpuaffinity extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_setcpuaffinity');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'pcntl_setcpuaffinity() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = null;
        $cpuIds = [];
        if ($argc >= 1) {
            $pidArg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pidArg->type) {
                $pid = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_setcpuaffinity', 0, 'process_id');
            }
        }
        if ($argc >= 2) {
            $ht = VmArray::requireArrayParam($frame->calledArgs[1], 'pcntl_setcpuaffinity', 2, 'cpu_ids');
            foreach ($ht->iterate(true) as $value) {
                $v = $value->resolveIndirect();
                if (Variable::TYPE_STRING === $v->type) {
                    $s = $v->toString();
                    // php-src ZEND_HANDLE_NUMERIC — numeric string cpu ids only
                    if (1 !== \preg_match('/^-?[0-9]+$/', $s)) {
                        throw new \ValueError(
                            \sprintf('pcntl_setcpuaffinity(): Argument #2 ($cpu_ids) cpu id invalid value (%s)', $s)
                        );
                    }
                    $cpuIds[] = (int) $s;
                } elseif (Variable::TYPE_INTEGER === $v->type
                    || Variable::TYPE_FLOAT === $v->type
                    || Variable::TYPE_BOOLEAN === $v->type
                    || Variable::TYPE_NULL === $v->type
                ) {
                    $cpuIds[] = $v->toInt();
                } else {
                    throw new \TypeError(
                        \sprintf(
                            'pcntl_setcpuaffinity(): Argument #2 ($cpu_ids) value must be of type int|string, %s given',
                            \PHPCompiler\VM\EnumCaseSupport::typeNameForVariable($v)
                        )
                    );
                }
            }
        }
        $frame->returnVar->bool(VmPcntl::setcpuaffinity($pid, $cpuIds));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_setcpuaffinity() is not implemented for JIT in this compiler build (issue #20510)');
    }
}
