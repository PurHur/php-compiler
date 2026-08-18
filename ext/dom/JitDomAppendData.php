<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMCharacterData::appendData() (php-src xmlTextConcat).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry append would abort. Fold compile-time concat like splitText.
 *
 * php-src: ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, appendData) (#32376)
 */
final class JitDomAppendData
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_appenddata_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMCharacterData::appendData',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))
        ) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMCharacterData::appendData(): Argument #1 ($data) must be of type string, null given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_appenddata_after_type');

            return self::boxNullResult($context);
        }

        $head = $args[0]->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomSubstringData::$lastMaterializedData;
        $tail = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $head || null === $tail) {
            throw new \LogicException(
                'DOMCharacterData::appendData() user-script AOT requires compile-time data'
            );
        }

        $combined = $head.$tail;
        $receiver = self::loadObjectArg($context, $args[0]);
        JitDomCreateTextNode::overwriteCharacterData($context, $receiver, $combined);
        $args[0]->compileTimeDomTextData = $combined;

        return self::boxTrueResult($context);
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

        throw new \LogicException('DOMCharacterData::appendData() receiver must be an object');
    }

    private static function boxTrueResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(1, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
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
