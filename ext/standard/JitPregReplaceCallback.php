<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\PregReplaceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Type as LlvmType;
use PHPLLVM\Value;

/** LLVM lowering for preg_replace_callback() via __compiler_preg_replace_callback (issue #1177). */
final class JitPregReplaceCallback
{
    private static int $blockSerial = 0;

    /** @var array<string, Value> per-module preg callback shims */
    private static array $callbackShims = [];

    /** @return Value
     * (string result, or boolean false on PCRE error) */
    public static function invoke(
        Context $context,
        Value $pattern,
        JITVariable $callback,
        Value $subject
    ): Value {
        StringPregMatch::ensureLinked($context);

        if (!PregReplaceCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(PregReplaceCallbackPolicy::jitRejectionMessage());
        }
        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(PregReplaceCallbackPolicy::jitRejectionMessage());
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \LogicException(
                "preg_replace_callback() callback '{$name}' is not a defined function in this compile unit"
            );
        }
        $proxy = $context->resolveFunctionProxy($name);
        if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
            throw new \LogicException(
                "preg_replace_callback() callback '{$name}' must be a user-defined function in this compile unit"
            );
        }

        $strPtrTy = $context->getTypeFromString('__string__*');
        $replaceCallbackFn = $context->lookupFunction('__compiler_preg_replace_callback_thin');
        if (null === $replaceCallbackFn) {
            $replaceCallbackFn = $context->lookupFunction('__compiler_preg_replace_callback');
        }
        $callbackPtrTy = $replaceCallbackFn->getParam(2)->typeOf();
        $shimFn = self::callbackShim($context, $proxy, $name, $callbackPtrTy);
        $callbackPtr = $context->builder->pointerCast($shimFn, $callbackPtrTy);
        $raw = $context->builder->call(
            $replaceCallbackFn,
            $pattern,
            $subject,
            $callbackPtr
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_replace_callback_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_replace_callback_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_replace_callback_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function callbackShim(
        Context $context,
        Native $userFn,
        string $name,
        LlvmType $callbackPtrTy
    ): Value {
        $moduleKey = spl_object_hash($context->module);
        $cacheKey = $moduleKey.'::'.$name;
        if (isset(self::$callbackShims[$cacheKey])) {
            return self::$callbackShims[$cacheKey];
        }

        $cbFnTy = $callbackPtrTy->getElementType();
        if (!$cbFnTy instanceof LlvmType\Function_) {
            throw new \LogicException('preg_replace_callback() runtime callback type is not a function pointer');
        }
        $valuePtrTy = $cbFnTy->getReturnType();
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?: 'fn';
        $shimName = '__preg_cb_shim_'.$safe;
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $arg = $shimFn->getParam(0);
        $argForRead = $context->builder->pointerCast(
            $arg,
            $context->getTypeFromString('__value__*')
        );
        $userParamTy = $userFn->function->getParam(0)->typeOf();
        $userParamTyName = $context->getStringFromType($userParamTy);
        if ('__hashtable__*' === $userParamTyName) {
            $callArg = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $argForRead
            );
        } elseif ('__value__*' === $userParamTyName) {
            $callArg = $argForRead;
        } elseif ('__value__' === $userParamTyName) {
            $callArg = $context->builder->load($argForRead);
        } else {
            throw new \LogicException(
                "preg_replace_callback() shim: unsupported callback param LLVM type {$userParamTyName} (#26820)"
            );
        }
        $replacement = $context->builder->call($userFn->function, $callArg);
        $userFnTy = $userFn->function->typeOf();
        if (\PHPLLVM\Type::KIND_POINTER === $userFnTy->getKind()) {
            $userFnTy = $userFnTy->getElementType();
        }
        if (!$userFnTy instanceof LlvmType\Function_) {
            throw new \LogicException('preg_replace_callback() callback is not an LLVM function (#26820)');
        }
        $userRetTyName = $context->getStringFromType($userFnTy->getReturnType());
        if ('__string__*' === $userRetTyName) {
            $replStr = $replacement;
        } else {
            $retSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
            if ('__value__*' === $userRetTyName) {
                $context->builder->store($context->builder->load($replacement), $retSlot);
            } else {
                $context->builder->store($replacement, $retSlot);
            }
            $retPtr = $context->builder->pointerCast($retSlot, $valuePtrTy);
            $replStr = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $retPtr
            );
        }
        $slot = JitValueBox::alloc($context);
        $writeStringFn = $context->lookupFunction('__value__writeString');
        $outSlotTy = $writeStringFn->getParam(0)->typeOf();
        $slotPtr = $context->builder->pointerCast($slot, $outSlotTy);
        $context->builder->call(
            $writeStringFn,
            $slotPtr,
            $replStr
        );
        $returnPtr = $context->builder->pointerCast($slot, $valuePtrTy);
        $context->builder->returnValue($returnPtr);
        $context->builder->positionAtEnd($resumeBlock);

        self::$callbackShims[$cacheKey] = $shimFn;

        return $shimFn;
    }
}
