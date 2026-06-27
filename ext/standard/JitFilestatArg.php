<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
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
        string $function
    ): Value {
        return JitStringBuiltinArg::lower($context, $arg, $function, 0, 'filename');
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
        }
    }

    public static function valuePtrAfterIntOrStringGuard(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        self::guardIntOrString($context, $arg, $function, $argIndex, $paramName);

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
