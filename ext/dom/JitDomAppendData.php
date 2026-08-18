<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
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
 * DomRegistry concat would abort. Fold compile-time data like
 * {@see JitDomSplitText} / {@see JitDomSubstringData}.
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

        // By-value null literals lower as TYPE_VALUE + isNullConstant (#19845).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant)
        ) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMCharacterData::appendData(): Argument #1 ($data) must be of type string, null given'
            );

            return self::boxNull($context);
        }

        $data = $args[0]->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomSubstringData::$lastMaterializedData;
        $suffix = self::compileTimeString($args[1]);
        if (null === $data || null === $suffix) {
            if (JitDomInstanceMethodKernel::shouldUse($context)) {
                throw new \LogicException(
                    'DOMCharacterData::appendData() user-script AOT requires compile-time data'
                );
            }

            return DomInstanceMethodRuntime::invoke($context, 1, 'appenddata', $args[0], $args[1]);
        }

        $combined = $data.$suffix;
        $receiverObj = self::loadObjectArg($context, $args[0]);
        JitDomCreateTextNode::overwriteCharacterData($context, $receiverObj, $combined);
        $args[0]->compileTimeDomTextData = $combined;

        return self::boxNull($context);
    }

    private static function compileTimeString(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }
        if (null !== $arg->compileTimeLong) {
            return (string) $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            return (string) $arg->compileTimeFloat;
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

        throw new \LogicException('DOMCharacterData::appendData() receiver must be an object');
    }

    private static function boxNull(Context $context): Value
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
