<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;

/** JIT/AOT guard for array_push() by-reference array argument (#4881). */
final class JitArrayPush
{
    public const BY_REF_ERROR =
        'array_push(): Argument #1 ($array) cannot be passed by reference';

    /**
     * @return bool false when compile-time operand is definitely non-array (caller must not lower push)
     */
    public static function requireByRefArrayArg(Context $context, JITVariable $array): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $array->type
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return true;
        }
        if (JITVariable::TYPE_VALUE === $array->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $array);
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($loaded, $typeField)
            );
            $i8 = $context->getTypeFromString('int8');
            $isArray = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            );
            $okBlock = BasicBlockHelper::append($context, 'array_push_req_ok');
            $errBlock = BasicBlockHelper::append($context, 'array_push_req_err');
            $context->builder->branchIf($isArray, $okBlock, $errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitPendingError($context);
            $context->builder->ret($context->constantFromInteger(0, 'int64'));
            $context->builder->positionAtEnd($okBlock);

            return true;
        }

        self::emitPendingError($context);

        return false;
    }

    private static function emitPendingError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, self::BY_REF_ERROR);
    }
}
