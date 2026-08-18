<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMText::splitText() (php-src xmlTextSplitText).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry split would abort. Fold compile-time data + offset like
 * {@see JitDomCreateTextNode}.
 *
 * php-src: ext/dom/text.c PHP_METHOD(DOMText, splitText) (#32362)
 */
final class JitDomSplitText
{
    /** Tail node data after the last compile-time split. */
    public static ?string $lastResultData = null;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        self::$lastResultData = null;
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_split_text_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMText::splitText() expects a receiver and offset');
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMText::splitText(): Argument #1 ($offset) must be of type int, null given'
            );

            return self::boxNullResult($context);
        }

        $data = $args[0]->compileTimeDomTextData ?? JitDomCreateTextNode::$lastMaterializedData;
        $offset = self::compileTimeOffset($args[1]);
        if (null === $data || null === $offset) {
            if (JitDomInstanceMethodKernel::shouldUse($context)) {
                throw new \LogicException(
                    'DOMText::splitText() user-script AOT requires compile-time data and offset'
                );
            }

            return DomInstanceMethodRuntime::invoke($context, 1, 'splittext', $args[0], $args[1]);
        }

        if ($offset < 0) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitValueErrorAndAbort(
                $context,
                'DOMText::splitText(): Argument #1 ($offset) must be greater than or equal to 0'
            );

            return self::boxNullResult($context);
        }

        $len = \strlen($data);
        if ($offset > $len) {
            return self::boxFalseResult($context);
        }

        $prefix = substr($data, 0, $offset);
        $suffix = substr($data, $offset);
        $receiverObj = self::loadObjectArg($context, $args[0]);
        JitDomCreateTextNode::overwriteCharacterData($context, $receiverObj, $prefix);
        $args[0]->compileTimeDomTextData = $prefix;
        self::$lastResultData = $suffix;

        return JitDomCreateTextNode::materialize($context, $suffix);
    }

    private static function compileTimeOffset(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            return (int) $arg->compileTimeFloat;
        }
        if (null !== $arg->compileTimeString && is_numeric($arg->compileTimeString)) {
            return (int) $arg->compileTimeString;
        }

        return null;
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMText::splitText() receiver must be an object');
    }

    private static function boxFalseResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
