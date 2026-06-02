<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** trigger_error() — user-level error/warning/notice (issue #1221). */
final class trigger_error_ extends Internal
{
    public function __construct()
    {
        parent::__construct('trigger_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('trigger_error() requires one or two arguments');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('trigger_error() requires VM context');
        }
        $messageVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $messageVar->type) {
            throw new \LogicException('trigger_error() message must be a string');
        }
        $level = ErrorReporter::E_USER_NOTICE;
        if (2 === $argc) {
            $levelVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \LogicException('trigger_error() error type must be an integer');
            }
            $level = $levelVar->toInt();
            if (!ErrorReporter::isUserErrorLevel($level)) {
                throw new \ValueError('trigger_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED');
            }
        }
        $frame->vmContext->errors->triggerError(
            $messageVar->toString(),
            $level,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('trigger_error() requires one or two arguments');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'trigger_error() message');
        }
        $literal = $args[0]->compileTimeString ?? null;
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === $literal) {
            throw new \LogicException('trigger_error() message must be a string literal in this compiler build');
        }
        $level = ErrorReporter::E_USER_NOTICE;
        if (2 === $argc) {
            $level = self::jitResolveErrorLevel($context, $args[1]);
        }
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($literal), $i8p);
        $msgLen = $sizeT->constInt(\strlen($literal), false);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt($level, false)
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function jitResolveErrorLevel(Context $context, JITVariable $arg): int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        if (null !== $arg->compileTimeConstantName) {
            $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($arg->compileTimeConstantName);
            if (null !== $errorInt) {
                return $errorInt;
            }
        }

        throw new \LogicException('trigger_error() error type must be a compile-time integer in this compiler build');
    }
}
