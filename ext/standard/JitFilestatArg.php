<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM guards for filestat permission builtins (php-src ext/standard/filestat.c; #6079). */
final class JitFilestatArg
{
    public static function lowerFilename(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'filename',
        bool $softNullPath = true
    ): Value {
        return JitStringBuiltinArg::lowerPath($context, $arg, $function, $argIndex, $paramName, 'string', null, $softNullPath);
    }

    /** basename/dirname/pathinfo — TypeError on null under PROFILE≥8.4 (#20099). */
    public static function lowerPathComponentFilename(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'path'
    ): Value {
        return self::lowerFilename($context, $arg, $function, $argIndex, $paramName, false);
    }

    /** touch() $filename — typed string; reject null (#18245, ext/standard/file.c). */
    public static function lowerPath(
        Context $context,
        JITVariable $arg,
        string $function
    ): Value {
        return JitStringBuiltinArg::lowerTypedString($context, $arg, $function, 0, 'filename');
    }

    public static function guardIntOrString(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        if (\in_array($arg->type, [JITVariable::TYPE_STRING, JITVariable::TYPE_NATIVE_LONG], true)) {
            return;
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort(
                    $context,
                    self::intOrStringTypeError($function, $argIndex, $paramName, 'null')
                );
            }

            return;
        }
        $enumClass = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumClass) {
            self::emitTypeErrorAndAbort(
                $context,
                self::intOrStringTypeError($function, $argIndex, $paramName, $enumClass)
            );

            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitValueBoxEnumReject(
                $context,
                $arg,
                self::intOrStringTypeError($function, $argIndex, $paramName, 'object')
            );
        }
    }

    public static function guardInt(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return;
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                self::intTypeError($function, $argIndex, $paramName, 'string')
            );

            return;
        }
        $enumClass = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumClass) {
            self::emitTypeErrorAndAbort(
                $context,
                self::intTypeError($function, $argIndex, $paramName, $enumClass)
            );

            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitValueBoxEnumReject(
                $context,
                $arg,
                self::intTypeError($function, $argIndex, $paramName, 'object')
            );

            return;
        }
        $given = JitOperandTypeLabel::givenLabel($context, $arg);
        self::emitTypeErrorAndAbort(
            $context,
            self::intTypeError($function, $argIndex, $paramName, $given)
        );
    }

    /** mkdir() mode — Z_PARAM_LONG decimal numeric strings (#17819, #18923, ext/standard/filestat.c). */
    public static function lowerFileMode(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        return self::lowerFileModeArg($context, $arg, $function, $argIndex, $paramName);
    }

    /** chmod() mode — Z_PARAM_LONG decimal numeric strings (#18923, ext/standard/filestat.c). */
    public static function lowerChmodMode(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        return self::lowerFileModeArg($context, $arg, $function, $argIndex, $paramName);
    }

    private static function lowerFileModeArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        // Z_PARAM_LONG: caller strict_types → TypeError on null (#31211 / #31213).
        // Catchable via ExceptionBridge; callers early-return so no mkdir/chmod invoke follows.
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false))) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::intTypeError($function, $argIndex, $paramName, 'null')
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_null_mode_te_cont');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        self::guardFileModeString($context, $arg, $function, $argIndex, $paramName);
        if (JITVariable::TYPE_STRING === $arg->type && null !== $arg->compileTimeString) {
            $i64 = $context->getTypeFromString('int64');

            return $i64->constInt(
                VmFilestatArg::parseFileModeString($arg->compileTimeString),
                false
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return JitLongArg::lowerStringValue(
                $context,
                $context->helper->loadValue($arg)
            );
        }

        return JitLongArg::lower($context, $arg, $function.'() '.$paramName);
    }

    private static function guardFileModeString(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        if (JITVariable::TYPE_STRING !== $arg->type) {
            return;
        }
        if ($context->callerStrictTypes) {
            self::emitTypeErrorAndAbort(
                $context,
                self::intTypeError($function, $argIndex, $paramName, 'string')
            );
        }
        if (null !== $arg->compileTimeString) {
            $raw = $arg->compileTimeString;
            if ('' === $raw || !is_numeric($raw)) {
                self::emitTypeErrorAndAbort(
                    $context,
                    self::intTypeError($function, $argIndex, $paramName, 'string')
                );
            }
        }
    }

    public static function coerceIntOrStringJitArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): JITVariable {
        self::guardIntOrString($context, $arg, $function, $argIndex, $paramName);
        if (JITVariable::TYPE_NULL !== $arg->type) {
            return $arg;
        }
        $i64 = $context->getTypeFromString('int64');

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(0, false)
        );
    }

    public static function valuePtrAfterIntOrStringGuard(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $arg = self::coerceIntOrStringJitArg($context, $arg, $function, $argIndex, $paramName);

        return JitValueBox::valuePtrFromVariable($context, $arg);
    }

    private static function emitValueBoxEnumReject(
        Context $context,
        JITVariable $arg,
        string $fallbackMessage
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $okBlock = BasicBlockHelper::append($context, 'filestat_arg_ok');
        $rejectBlock = BasicBlockHelper::append($context, 'filestat_arg_reject');
        $context->builder->branchIf($isEnumCase, $rejectBlock, $okBlock);
        $context->builder->positionAtEnd($rejectBlock);
        self::emitTypeErrorAndAbort($context, $fallbackMessage);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function intOrStringTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type string|int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    private static function intTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }
}
