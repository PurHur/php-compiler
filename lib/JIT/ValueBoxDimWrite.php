<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\VM\StringOffsetJitHelper;
use PHPCfg\Operand;

/**
 * String-offset FETCH_DIM_W for {@see Variable::TYPE_VALUE} boxes typed as string (#32764).
 *
 * Read path already branches on the string type tag ({@see Variable::dimFetchValueBoxRead},
 * #22646). Writes must not call {@see HashTableHelper::ensureHashtablePointer} first — that
 * allocates an empty hashtable and {@see __value__writeHashtable} clobbers the string, so
 * `$s='abc'; $s[1]='Z'` becomes `array(1 => 'Z')` under thin AOT.
 *
 * Script locals use {@see Variable::$functionStaticGlobal}. #32814 routed those to the string
 * path whenever CFG was not a pure array — unions that include array (untyped static by-value
 * copy #32830) and unknown CFG with a hashtable in the box (json_decode) then SIGSEGV. Skip the
 * string path when {@see containerCfgMayBeArray} or {@see Variable::$valueBoxHashtable}.
 *
 * php-src: Zend/zend_execute.c — zend_assign_to_string_offset / ZEND_ASSIGN_DIM
 */
final class ValueBoxDimWrite
{
    /**
     * Emit a writable char* lvalue into {@see $resultOp} for a value box holding a string.
     *
     * Separates the string (COW), writes it back into the box, then exposes the byte pointer
     * so {@see StringOffsetHelper::dimAssign} via {@see \PHPCompiler\JIT::assignOperand} stores
     * the RHS into the string in place.
     */
    public static function fetchStringOffsetWriteLvalue(
        Context $context,
        Variable $container,
        Variable $dim,
        Operand $resultOp
    ): void {
        $ptr = JitValueBox::valuePtrFromVariable($context, $container);

        if (Variable::TYPE_STRING === $dim->type || Variable::TYPE_OBJECT === $dim->type) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            $label = 'string';
            if (Variable::TYPE_OBJECT === $dim->type) {
                $label = $dim->classUserType ?? 'object';
            }
            ErrorRaise::emitRaise(
                $context,
                StringOffsetJitHelper::illegalDimTypeErrorMessage($label)
            );
            $context->makeVariableFromValueOp(
                $context->getTypeFromString('int8*')->constNull(),
                $resultOp
            );

            return;
        }
        if (Variable::emitIllegalStringOffsetDimGuard($context, $dim)) {
            $context->makeVariableFromValueOp(
                $context->getTypeFromString('int8*')->constNull(),
                $resultOp
            );

            return;
        }
        $dimLong = Variable::coerceStringOffsetDimToLong($context, $dim);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $ptr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $charPtr = StringOffsetHelper::dimFetch($context, $owned, $dimLong);
        $context->makeVariableFromValueOp($charPtr, $resultOp);
    }

    /**
     * True when CFG types the dim container as string (boxed locals keep TYPE_VALUE).
     */
    public static function containerCfgIsString(?\PHPTypes\Type $type): bool
    {
        return null !== $type && \PHPTypes\Type::TYPE_STRING === $type->type;
    }

    /**
     * True when CFG types the dim container as array (function-static array defaults, #32806).
     */
    public static function containerCfgIsArray(?\PHPTypes\Type $type): bool
    {
        return null !== $type && \PHPTypes\Type::TYPE_ARRAY === $type->type;
    }

    /**
     * True when CFG is array or a union/intersection that includes array (#32830).
     */
    public static function containerCfgMayBeArray(?\PHPTypes\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if (\PHPTypes\Type::TYPE_ARRAY === $type->type) {
            return true;
        }
        if (
            \PHPTypes\Type::TYPE_UNION !== $type->type
            && \PHPTypes\Type::TYPE_INTERSECTION !== $type->type
        ) {
            return false;
        }
        foreach ($type->subTypes ?? [] as $sub) {
            if (self::containerCfgMayBeArray($sub)) {
                return true;
            }
        }

        return false;
    }
}
