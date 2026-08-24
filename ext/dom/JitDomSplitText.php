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
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMText::splitText() (php-src xmlTextSplitText).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry split would abort. Fold compile-time data + offset like
 * {@see JitDomCreateTextNode}. In-tree receivers (loadXML firstChild) also
 * link the suffix as nextSibling via ChildNode::after LiveSlots (#34314 / #34475).
 *
 * php-src: ext/dom/text.c PHP_METHOD(DOMText, splitText) (#32362, #34314)
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

        $data = $args[0]->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomSubstringData::$lastMaterializedData;
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

        $tailObj = JitDomCreateTextNode::materialize($context, $suffix);
        self::linkTailAfterReceiver($context, $receiverObj, $args[0], $tailObj);

        return $tailObj;
    }

    /**
     * php-src xmlTextSplitText inserts the new node after $node when parented.
     * Detached createTextNode (#32362) has a null parentNode — skip linking.
     */
    private static function linkTailAfterReceiver(
        Context $context,
        Value $receiverObj,
        JITVariable $receiverVar,
        Value $tailObj
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_split_link');
        $parent = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $receiverObj,
            VmDom::PROP_PARENT_NODE,
            'dom_split_parent'
        );
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $parent, $objPtrTy->constNull());
        $bbLink = BasicBlockHelper::append($context, 'dom_split_link_do');
        $bbDone = BasicBlockHelper::append($context, 'dom_split_link_done');
        $context->builder->branchIf($isNull, $bbDone, $bbLink);

        $context->builder->positionAtEnd($bbLink);
        $parentVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
        $tailVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $tailObj);
        $tailVar->compileTimeDomTextData = self::$lastResultData;
        JitDomChildNodeSiblingInsert::invokeAfter($context, $parentVar, $tailVar, $receiverVar);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
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
