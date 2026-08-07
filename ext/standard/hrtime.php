<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hrtime() — monotonic clock (VM + JIT/AOT via VmHrtimeNative / HrtimeJitHelper, #5634/#7315/#9182). */
final class hrtime extends Internal
{
    public function __construct()
    {
        parent::__construct('hrtime');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28691).
        $this->requireAtMostArgCount($frame, 'hrtime', 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $asNumber = false;
        if (1 === $argc) {
            $asNumber = VmMath::parseBoolBuiltinArgForFrame($frame, 0, 'hrtime', 1, 'as_number');
        }
        if ($asNumber) {
            $value = VmDate::hrtime(true);
            if (\is_int($value)) {
                $frame->returnVar->int($value);
            } else {
                $frame->returnVar->float((float) $value);
            }

            return;
        }
        $pair = VmDate::hrtime(false);
        $ht = new HashTable();
        $sec = new Variable(Variable::TYPE_INTEGER);
        $sec->int((int) $pair[0]);
        $ht->addIndex(0, $sec);
        $nsec = new Variable(Variable::TYPE_INTEGER);
        $nsec->int((int) $pair[1]);
        $ht->addIndex(1, $nsec);
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireAtMostJitArgCount($context, $args, 'hrtime', 1)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $asNumber = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asNumber = JitBoolArg::lowerZParamBool($context, $args[0], 'hrtime', 'as_number', 1);
        }

        return JitDate::hrtime($context, $asNumber);
    }
}
