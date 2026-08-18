<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMCharacterData::replaceData() (php-src xmlTextReplace).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry replace would abort. Fold compile-time splice like
 * {@see JitDomDeleteData} / {@see JitDomInsertData}.
 *
 * php-src: ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, replaceData) (#32391)
 */
final class JitDomReplaceData
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacedata_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMCharacterData::replaceData',
            3
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        if ($context->callerStrictTypes) {
            if (self::isNullArg($args[1] ?? null)) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'DOMCharacterData::replaceData(): Argument #1 ($offset) must be of type int, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacedata_after_off_type');

                return self::boxTrueResult($context);
            }
            if (self::isNullArg($args[2] ?? null)) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'DOMCharacterData::replaceData(): Argument #2 ($count) must be of type int, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacedata_after_count_type');

                return self::boxTrueResult($context);
            }
            if (self::isNullArg($args[3] ?? null)) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'DOMCharacterData::replaceData(): Argument #3 ($data) must be of type string, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacedata_after_data_type');

                return self::boxTrueResult($context);
            }
        }

        $data = $args[0]->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomSubstringData::$lastMaterializedData;
        $offset = self::compileTimeLong($args[1] ?? null);
        $count = self::compileTimeLong($args[2] ?? null);
        $repl = null;
        if (isset($args[3])) {
            $repl = JitStringBuiltinArg::compileTimeLiteral($args[3]) ?? $args[3]->compileTimeString;
        }
        if (null === $data || null === $offset || null === $count || null === $repl) {
            throw new \LogicException(
                'DOMCharacterData::replaceData() user-script AOT requires compile-time data'
            );
        }

        $len = \strlen($data);
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
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacedata_index_cont');

            return self::boxTrueResult($context);
        }
        if ($count > $len - $offset) {
            $count = $len - $offset;
        }

        $combined = substr($data, 0, $offset).$repl.substr($data, $offset + $count);
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

        throw new \LogicException('DOMCharacterData::replaceData() receiver must be an object');
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
