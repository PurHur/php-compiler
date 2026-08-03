<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionCreateIdRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** PHP lowering for session_create_id() — {@see __phpc_session_create_id_apply} (#6002, #27258). */
final class JitSessionCreateId
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \LogicException('session_create_id() accepts at most one argument in this compiler build');
        }

        // Thin STANDALONE/EMBED AOT skips Type::initialize eager SessionCreateIdRuntime
        // link (#12910 / #21109) — declare-only `__phpc_session_create_id_apply` then
        // fails at ld. Lazy-link on first use (peer JitSessionStart / #27258).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SessionCreateIdRuntime::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $nullStr = $context->getTypeFromString('__string__*')->constNull();

        if (0 === $argc) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_create_id_apply'),
                $ptr,
                $nullStr
            );

            return $ptr;
        }

        $arg = $args[0];
        if (JITVariable::TYPE_NULL === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_create_id_apply'),
                $ptr,
                $nullStr
            );

            return $ptr;
        }

        if (JITVariable::TYPE_STRING === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_session_create_id_apply'),
                $ptr,
                $context->helper->loadValue($arg)
            );

            return $ptr;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitTypeErrorIfEnumCase($context, $arg, 'session_create_id', 0, 'prefix');
            $boxed = $context->helper->loadValue($arg);
            $context->builder->call(
                $context->lookupFunction('__phpc_session_create_id_apply_boxed'),
                $ptr,
                $boxed
            );

            return $ptr;
        }

        $prefix = JitStringBuiltinArg::lower(
            $context,
            $arg,
            'session_create_id',
            0,
            'prefix',
            '?string'
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_session_create_id_apply'),
            $ptr,
            $prefix
        );

        return $ptr;
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
        $okBlock = BasicBlockHelper::append($context, 'scid_enum_ok');
        $errBlock = BasicBlockHelper::append($context, 'scid_enum_err');
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
