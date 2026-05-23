<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * trigger_error() — user-level errors/warnings/notices (issue #1221).
 */
final class trigger_error extends Internal
{
    public function __construct()
    {
        parent::__construct('trigger_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('trigger_error() expects one or two arguments');
        }
        $msgVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $msgVar->type) {
            throw new \LogicException('trigger_error() message must be a string in this compiler build');
        }
        $type = ErrorReporter::E_USER_NOTICE;
        if (2 === $argc) {
            $typeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $typeVar->type) {
                throw new \LogicException('trigger_error() type must be an integer in this compiler build');
            }
            $type = $typeVar->toInt();
        }
        if (null !== $frame->vmContext) {
            $file = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            $frame->vmContext->errors->triggerError($msgVar->toString(), $type, $file);

            return;
        }
        if (\function_exists('trigger_error')) {
            \trigger_error($msgVar->toString(), $type);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('trigger_error() expects one or two arguments');
        }
        if (!\in_array($args[0]->type, [JITVariable::TYPE_STRING, JITVariable::TYPE_VALUE], true)) {
            throw new \LogicException('trigger_error() message must be a string in this compiler build');
        }
        $msgPtr = $this->jitString($context, $args[0], 'trigger_error() message');
        $i32 = $context->getTypeFromString('int32');
        $typeI32 = $i32->constInt(ErrorReporter::E_USER_NOTICE, false);
        if (2 === $argc) {
            $typeI32 = JitLongArg::lower($context, $args[1], 'trigger_error() type');
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('trigger_error() type must be an integer in this compiler build');
            }
            $typeI32 = $context->builder->trunc($typeI32, $i32);
        }
        JitTriggerError::emit($context, $msgPtr, $typeI32);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
