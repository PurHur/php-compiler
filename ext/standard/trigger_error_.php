<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** trigger_error() — user-level error/warning/notice (issue #1221); Z_PARAM_STR null on 8.4 (#21035). */
final class trigger_error_ extends Internal
{
    public function __construct()
    {
        parent::__construct('trigger_error');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28690).
        $this->requireArgCountRange($frame, 'trigger_error', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->vmContext) {
            throw new \LogicException('trigger_error() requires VM context');
        }
        // Soft-null DEP+coerce on 8.4 (php-src Z_PARAM_STR; #21480, reverts #21035 TypeError).
        $message = VmString::coerceTrimFamilyStringArg($frame->calledArgs[0], 'trigger_error', 0, 'message');
        $level = ErrorReporter::E_USER_NOTICE;
        if (2 === $argc) {
            $level = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'trigger_error', 2, 'error_level');
            if (!ErrorReporter::isUserErrorLevel($level)) {
                throw new \ValueError('trigger_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED');
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
        // Catchable ArgumentCountError (AOT/JIT) — #28690.
        if (!$this->requireArgCountRangeJit($context, $args, 'trigger_error', 1, 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $argc = \count($args);
        // Soft-null DEP+coerce on 8.4 (#21480, reverts #21035 TypeError).
        $msgStr = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'trigger_error', 0, 'message');
        $levelVal = 2 === $argc
            ? $this->jitLowerUserErrorLevel($context, $args[1])
            : $context->getTypeFromString('int32')->constInt(ErrorReporter::E_USER_NOTICE, false);
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

    private function jitLowerUserErrorLevel(Context $context, JITVariable $arg): Value
    {
        $compileTime = self::tryCompileTimeErrorLevel($context, $arg);
        if (null !== $compileTime) {
            if (!ErrorReporter::isUserErrorLevel($compileTime)) {
                throw new \ValueError(
                    'trigger_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED'
                );
            }

            return $context->getTypeFromString('int32')->constInt($compileTime, false);
        }

        $levelI32 = $context->builder->trunc(
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $arg, 'trigger_error', 2, 'error_level'),
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
            $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_ERROR, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_WARNING, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_NOTICE, false)),
                    $context->builder->icmp(Builder::INT_EQ, $levelI32, $i32->constInt(ErrorReporter::E_USER_DEPRECATED, false))
                )
            )
        );
        $ok = BasicBlockHelper::append($context, 'trigger_error_level_ok');
        $bad = BasicBlockHelper::append($context, 'trigger_error_level_bad');
        $context->builder->branchIf($isValid, $ok, $bad);
        $context->builder->positionAtEnd($bad);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'trigger_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED'
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->builder->positionAtEnd($ok);
    }
}
