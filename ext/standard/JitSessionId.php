<?php

declare(strict_types=1);

/**
 * PHP lowering for session_id() — single callee {@see SessionId::APPLY_*}.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionId as Sid;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitSessionId
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \LogicException('session_id() accepts at most one argument');
        }

        // STANDALONE Type::register skips SessionId::implement (#32989).
        Sid::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $nullBoxed = $context->getTypeFromString('__value__*')->constNull();

        if (0 === $argc) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_GET, false),
                $nullStr,
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        $arg = $args[0];
        if (JITVariable::TYPE_STRING === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_SET, false),
                $context->helper->loadValue($arg),
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_NULL === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_GET, false),
                $nullStr,
                $nullBoxed,
                $ptr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitTypeErrorIfEnumCase($context, $arg, 'session_id', 0, 'id');
            $boxed = $context->helper->loadValue($arg);
            $context->builder->call(
                $context->lookupFunction('__phpc_session_id_apply'),
                $i8->constInt(Sid::APPLY_BOXED, false),
                $nullStr,
                $boxed,
                $ptr
            );

            return $ptr;
        }

        throw new \LogicException('session_id() id must be a string in this compiler build');
    }

    private static function emitTypeErrorIfEnumCase(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $okBlock = BasicBlockHelper::append($context, 'sid_enum_ok');
        $errBlock = BasicBlockHelper::append($context, 'sid_enum_err');
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $context->builder->branchIf($isEnumCase, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        $given = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg) ?? 'object';
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
