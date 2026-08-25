<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Z_PARAM_OBJ_OF_CLASS(DOMNode) guard — Zend TypeError (#30410 / #32558 / #33716).
 *
 * php-src: ext/dom/php_dom.stub.php — DOMNode::appendChild(DOMNode $node), etc.
 * Literal null is TYPE_VALUE + isNullConstant; variable null is TYPE_VALUE without
 * that flag — {@see __value__readObject} on a null box SIGSEGVs under AOT (#33716).
 * Non-null scalars (int/bool/float/string/array) must TypeError at runtime like Zend
 * instead of LogicException at compile time or SIGSEGV on readObject (#34716).
 * Runtime branch mirrors {@see JitDomSaveOptionalDomNodeArg} (#34225) and
 * {@see JitDomInsertBefore} refChild (#33031).
 */
final class JitDomRequireDomNodeArg
{
    private static int $seq = 0;

    /**
     * @return bool true when compile-time invalid type was handled (caller must return immediately)
     */
    public static function guardOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass = 'DOMNode'
    ): bool {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return false;
        }

        if (self::isCompileTimeNull($arg)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_null');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::message($function, $userArgIndex, $paramName, $expectedClass, 'null')
            );

            return true;
        }

        $scalar = self::compileTimeScalarLabel($arg);
        if (null !== $scalar) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_scalar');
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::message($function, $userArgIndex, $paramName, $expectedClass, $scalar)
            );

            return true;
        }

        // Variable null / boxed scalars: TYPE_VALUE, no isNullConstant / native type.
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitRuntimeNonObjectTypeErrorOrContinue(
                $context,
                $arg,
                $function,
                $userArgIndex,
                $paramName,
                $expectedClass
            );
        }

        return false;
    }

    /**
     * ParentNode::append/prepend/replaceChildren — DOMNode|string (php-src parentnode.c; #33741).
     *
     * Strings are valid; only null TypeErrors. Message matches Zend (no $param name).
     *
     * @return bool true when compile-time null was handled (caller must return immediately)
     */
    public static function guardDomNodeOrStringOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex
    ): bool {
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            return false;
        }

        $message = \sprintf(
            '%s(): Argument #%d must be of type DOMNode|string, null given',
            $function,
            $userArgIndex
        );

        if (self::isCompileTimeNull($arg)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_or_str_null');
            ExceptionBridge::emitTypeErrorAndAbort($context, $message);

            return true;
        }

        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::emitRuntimeNullTypeErrorOrContinue($context, $arg, $message);
        }

        return false;
    }

    public static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    private static function compileTimeScalarLabel(JITVariable $arg): ?string
    {
        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function message(
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $userArgIndex,
            $paramName,
            $expectedClass,
            $given
        );
    }

    /**
     * Reject runtime null / int / string / bool / float / array before readObject.
     *
     * Leaves the builder positioned in the non-scalar continuation block.
     */
    private static function emitRuntimeNonObjectTypeErrorOrContinue(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_rt');
        $tag = (string) (self::$seq++);
        $okBlock = BasicBlockHelper::append($context, 'dom_req_node_rt_ok_'.$tag);

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $checks = [
            [JITVariable::TYPE_NULL, 'null'],
            [JITVariable::TYPE_NATIVE_LONG, 'int'],
            [JITVariable::TYPE_NATIVE_DOUBLE, 'float'],
            [JITVariable::TYPE_NATIVE_BOOL, 'bool'],
            [JITVariable::TYPE_STRING, 'string'],
            [JITVariable::TYPE_HASHTABLE, 'array'],
        ];
        foreach ($checks as [$typeConst, $label]) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt($typeConst & 0x7f, false)
            );
            $labelBlock = BasicBlockHelper::append($context, 'dom_req_node_rt_'.$label.'_'.$tag);
            $nextProbe = BasicBlockHelper::append($context, 'dom_req_node_rt_next_'.$label.'_'.$tag);
            $context->builder->branchIf($match, $labelBlock, $nextProbe);
            $context->builder->positionAtEnd($labelBlock);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                self::message($function, $userArgIndex, $paramName, $expectedClass, $label)
            );
            $context->builder->positionAtEnd($nextProbe);
        }

        $context->builder->branch($okBlock);
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * Branch on boxed type tag before readObject (#33716) — null-only (DOMNode|string path).
     *
     * Leaves the builder positioned in the non-null continuation block.
     */
    private static function emitRuntimeNullTypeErrorOrContinue(
        Context $context,
        JITVariable $arg,
        string $message
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_rt_null');
        $tag = (string) (self::$seq++);
        $nullBlock = BasicBlockHelper::append($context, 'dom_req_node_rt_null_'.$tag);
        $okBlock = BasicBlockHelper::append($context, 'dom_req_node_rt_ok_'.$tag);

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $okBlock);

        $context->builder->positionAtEnd($nullBlock);
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);

        $context->builder->positionAtEnd($okBlock);
    }
}
