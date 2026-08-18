<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMCharacterData::deleteData() (php-src xmlUTF8Strsub).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry delete would abort. Fold compile-time delete like
 * {@see JitDomInsertData} / {@see JitDomAppendData}.
 *
 * php-src: ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, deleteData) (#32389)
 */
final class JitDomDeleteData
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_deletedata_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMCharacterData::deleteData',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        if ($context->callerStrictTypes) {
            if (self::isNullArg($args[1] ?? null)) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'DOMCharacterData::deleteData(): Argument #1 ($offset) must be of type int, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_deletedata_after_off_type');

                return self::boxTrueResult($context);
            }
            if (self::isNullArg($args[2] ?? null)) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'DOMCharacterData::deleteData(): Argument #2 ($count) must be of type int, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_deletedata_after_count_type');

                return self::boxTrueResult($context);
            }
        }

        $head = $args[0]->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomSubstringData::$lastMaterializedData;
        $offset = self::compileTimeLong($args[1] ?? null);
        $count = self::compileTimeLong($args[2] ?? null);
        if (null === $head || null === $offset || null === $count) {
            throw new \LogicException(
                'DOMCharacterData::deleteData() user-script AOT requires compile-time data'
            );
        }

        $len = \strlen($head);
        if ($offset < 0 || $offset > $len || $count < 0) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'DOMException',
                'Index Size Error',
                null,
                '',
                0,
                DomExceptionConstants::INDEX_SIZE_ERR
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_deletedata_index_cont');

            return self::boxTrueResult($context);
        }

        if ($count > $len - $offset) {
            $count = $len - $offset;
        }
        $combined = substr($head, 0, $offset).substr($head, $offset + $count);
        $receiver = self::loadObjectArg($context, $args[0]);
        JitDomCreateTextNode::overwriteCharacterData($context, $receiver, $combined);
        $args[0]->compileTimeDomTextData = $combined;

        return self::boxTrueResult($context);
    }

    private static function isNullArg(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return false;
        }

        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    private static function compileTimeLong(?JITVariable $arg): ?int
    {
        if (null === $arg) {
            return null;
        }
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

        throw new \LogicException('DOMCharacterData::deleteData() receiver must be an object');
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
}
