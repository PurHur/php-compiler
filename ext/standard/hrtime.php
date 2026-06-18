<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
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
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('hrtime() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $asNumber = false;
        if (1 === $argc) {
            $asNumber = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'hrtime',
                1,
                'as_number'
            );
        }
        if ($asNumber) {
            $frame->returnVar->int(VmDate::hrtime(true));

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
        if (\count($args) > 1) {
            throw new \LogicException('hrtime() accepts at most one argument');
        }
        $asNumber = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asNumber = JitBoolArg::lower($context, $args[0], 'hrtime(): Argument #1 ($as_number)');
        }

        return JitDate::hrtime($context, $asNumber);
    }
}
