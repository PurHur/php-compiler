<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * user_error() — alias of trigger_error() (Zend/zend_builtin_functions.stub.php, #6183 / #21480).
 *
 * Soft-null DEP+coerce for $message on PHP 8.4 forward profile.
 */
final class user_error extends Internal
{
    public function __construct()
    {
        parent::__construct('user_error');
    }

    public function execute(Frame $frame): void
    {
        // php-src Zend/zend_builtin_functions.c — same 1..2 arity as trigger_error (#30690).
        $this->requireArgCountRange($frame, 'user_error', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->vmContext) {
            throw new \LogicException('user_error() requires VM context');
        }
        // Caller strict_types → TypeError on null; else soft-null DEP+coerce (#21480 / #30018).
        $message = VmString::trimFamilyStringArgForFrame($frame, 0, 'user_error', 0, 'message');
        $level = ErrorReporter::E_USER_NOTICE;
        if (2 === $argc) {
            $levelVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \TypeError('user_error(): Argument #2 ($error_type) must be of type int');
            }
            $level = $levelVar->toInt();
            if (!ErrorReporter::isUserErrorLevel($level)) {
                throw new \ValueError('user_error(): Argument #2 ($error_type) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED');
            }
        }
        $file = null;
        $line = 0;
        $caller = $frame->parent;
        if (null !== $caller) {
            if ('' !== $caller->scriptPath) {
                $file = $caller->scriptPath;
            }
            $line = $caller->callSiteLine;
        } elseif ('' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        // Alias of trigger_error — same E_USER_ERROR deprecation text (#29216).
        trigger_error_::maybeDeprecateUserError($frame, $level, $file, $line);
        $frame->vmContext->errors->triggerError(
            $message,
            $level,
            $file,
            $frame->vmContext,
            $frame,
            $line
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #30690 (sibling trigger_error #28690).
        if (!$this->requireArgCountRangeJit($context, $args, 'user_error', 1, 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $argc = \count($args);
        $msgStr = trigger_error_::jitLowerMessage($context, $args[0], 'user_error');
        $levelVal = 2 === $argc
            ? self::jitLowerUserErrorLevel($context, $args[1])
            : $context->getTypeFromString('int32')->constInt(ErrorReporter::E_USER_NOTICE, false);
        trigger_error_::jitMaybeDeprecateUserError($context, $levelVal);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $map = $context->structFieldMap['__string__'];
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgStr, $map['length'])
        );
        $msgLen = $context->builder->zExt(
            $context->builder->trunc($msgLen, $i32),
            $sizeT
        );
        $msgPtr = $context->builder->structGep($msgStr, $map['value']);
        $filePath = '';
        if (null !== $context->jitEnclosingBlock) {
            $filePath = ScriptMagic::stringForBlock($context->jitEnclosingBlock, OpCode::SCRIPT_MAGIC_FILE);
        }
        $filePtr = $context->builder->pointerCast($context->constantFromString($filePath), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $levelVal,
            $filePtr,
            $i32->constInt($context->callSiteLine, false)
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function jitLowerUserErrorLevel(Context $context, JITVariable $arg): Value
    {
        $compileTime = self::tryCompileTimeErrorLevel($context, $arg);
        if (null !== $compileTime) {
            if (!ErrorReporter::isUserErrorLevel($compileTime)) {
                throw new \ValueError(
                    'user_error(): Argument #2 ($error_type) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED'
                );
            }

            return $context->getTypeFromString('int32')->constInt($compileTime, false);
        }

        $levelI32 = $context->builder->trunc(
            JitLongArg::lower($context, $arg, 'user_error() error type'),
            $context->getTypeFromString('int32')
        );
        self::jitGuardUserErrorLevel($context, $levelI32);

        return $levelI32;
    }

    private static function tryCompileTimeErrorLevel(Context $context, JITVariable $arg): ?int
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $lib = $context->llvm->lib;
            if (null !== $arg->value && null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        if (null !== $arg->compileTimeConstantName) {
            $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($arg->compileTimeConstantName);
            if (null !== $errorInt) {
                return $errorInt;
            }
        }

        return null;
    }

    private static function jitGuardUserErrorLevel(Context $context, Value $levelI32): void
    {
        $i32 = $context->getTypeFromString('int32');
        $isValid = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_NOTICE, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_ERROR, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_WARNING, false)),
                    $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_DEPRECATED, false))
                )
            )
        );
        $ok = BasicBlockHelper::append($context, 'user_error_level_ok');
        $bad = BasicBlockHelper::append($context, 'user_error_level_bad');
        $context->builder->branchIf($isValid, $ok, $bad);
        $context->builder->positionAtEnd($bad);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'user_error(): Argument #2 ($error_type) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED'
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->builder->positionAtEnd($ok);
    }
}
