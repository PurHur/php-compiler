<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * @internal libc getenv(3) kernel for GetenvJitHelper (#20644).
 *
 * Avoids VmEnvEnvironNative /proc walk when the helper TU is NestedJIT'd into
 * user-script AOT (NestedJIT FS leaf peer {@see \PHPCompiler\JIT\Builtin\StringRename}).
 */
final class phpc_getenv_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_getenv_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_getenv_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $nameVar->type) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        $name = $nameVar->toString();
        $val = \getenv($name);
        if (null !== $frame->returnVar) {
            if (false === $val) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->string((string) $val);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_getenv_kernel() expects exactly 1 argument');
        }
        $name = JitStringBuiltinArg::lowerNullableString(
            $context,
            $args[0],
            'phpc_getenv_kernel',
            0,
            'name'
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $name,
            $strPtr->constNull()
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $lookupBb = $fn->appendBasicBlock('getenv_kernel_name_ok');
        $nullBb = $fn->appendBasicBlock('getenv_kernel_name_null');
        $doneBb = $fn->appendBasicBlock('getenv_kernel_name_done');
        $context->builder->branchIf($isNull, $nullBb, $lookupBb);

        $context->builder->positionAtEnd($lookupBb);
        $found = JitGetenvKernel::invoke($context, $name);
        $lookupEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $nullStr = $strPtr->constNull();
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr, 'getenv_kernel_name_result');
        $phi->addIncoming($found, $lookupEnd);
        $phi->addIncoming($nullStr, $nullEnd);

        return $phi;
    }
}
