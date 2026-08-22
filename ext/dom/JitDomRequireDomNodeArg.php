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
 * Runtime branch mirrors {@see JitDomInsertBefore} refChild (#33031).
 */
final class JitDomRequireDomNodeArg
{
    private static int $seq = 0;

    /**
     * @return bool true when compile-time null was handled (caller must return immediately)
     */
    public static function guardOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass = 'DOMNode'
    ): bool {
        $message = \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, null given',
            $function,
            $userArgIndex,
            $paramName,
            $expectedClass
        );

        if (self::isCompileTimeNull($arg)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_null');
            ExceptionBridge::emitTypeErrorAndAbort($context, $message);

            return true;
        }

        // Variable null ($n = null / getElementById miss): TYPE_VALUE, no isNullConstant.
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

    /**
     * Branch on boxed type tag before readObject (#33716).
     *
     * Leaves the builder positioned in the non-null continuation block.
     */
    private static function emitRuntimeNullTypeErrorOrContinue(
        Context $context,
        JITVariable $arg,
        string $message
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_req_node_rt');
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
