<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\PregReplaceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\VmBoundMethodCallable;
use PHPLLVM\Builder;
use PHPLLVM\Type as LlvmType;
use PHPLLVM\Value;

/**
 * LLVM lowering for preg_replace_callback() via __compiler_preg_replace_callback (issue #1177).
 *
 * Static array callables `['Class','method']` (Nyholm Uri.php / #36382) fold to the same
 * shim path as string user-function names. php-src: ext/pcre/php_pcre.c.
 */
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

        [$proxy, $shimKey] = self::resolveCallbackNative($context, $callback);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $replaceCallbackFn = $context->lookupFunction('__compiler_preg_replace_callback_thin');
        if (null === $replaceCallbackFn) {
            $replaceCallbackFn = $context->lookupFunction('__compiler_preg_replace_callback');
        }
        $callbackPtrTy = $replaceCallbackFn->getParam(2)->typeOf();
        $shimFn = self::callbackShim($context, $proxy, $shimKey, $callbackPtrTy);
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

    /** @return array{0:Native,1:string} proxy + stable shim cache key */
    private static function resolveCallbackNative(Context $context, JITVariable $callback): array
    {
        $name = $callback->compileTimeString ?? null;
        if (null !== $name && PregReplaceCallbackPolicy::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $name
        )) {
            return [self::requireUserFunctionNative($context, $name), $name];
        }

        $staticNames = PregReplaceCallbackPolicy::compileTimeStaticArrayCallableNames($callback)
            ?? self::resolveStaticArrayCallableNamesFromBlock($context);
        if (null !== $staticNames) {
            $proxy = self::requireStaticMethodNative($context, $staticNames[0], $staticNames[1]);
            $classLc = strtolower(ltrim($staticNames[0], '\\'));
            $methodLc = strtolower($staticNames[1]);
            $proxyName = self::resolveStaticProxyForClass($context, $classLc, $methodLc) ?? ($classLc.'::'.$methodLc);

            return [$proxy, $proxyName];
        }

        throw new \LogicException(PregReplaceCallbackPolicy::jitRejectionMessage());
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function resolveStaticArrayCallableNamesFromBlock(Context $context): ?array
    {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        $callbackOp = $context->scope->argOperands[1] ?? null;
        if (!$block instanceof Block || !($callbackOp instanceof Operand)) {
            return null;
        }
        $slot = $block->slotForOperand($callbackOp);
        if (null === $slot) {
            return null;
        }
        $slots = VmBoundMethodCallable::resolveStaticArrayCallableSlots($block, $slot);
        if (null === $slots) {
            return null;
        }
        $constBlock = $slots[2];
        if (!isset($constBlock->constants[$slots[0]], $constBlock->constants[$slots[1]])) {
            return null;
        }
        $className = $constBlock->constants[$slots[0]]->toString();
        $methodName = $constBlock->constants[$slots[1]]->toString();
        if ('' === $className || '' === $methodName) {
            return null;
        }

        return [$className, $methodName];
    }

    private static function requireUserFunctionNative(Context $context, string $name): Native
    {
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

        return $proxy;
    }

    private static function requireStaticMethodNative(
        Context $context,
        string $className,
        string $methodName
    ): Native {
        $classLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        $proxyName = self::resolveStaticProxyForClass($context, $classLc, $methodLc);
        if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
            throw new \LogicException(
                "preg_replace_callback() callback [{$className}, {$methodName}] is not a defined static method "
                .'in this compile unit (#36382)'
            );
        }
        $proxy = $context->resolveFunctionProxy($proxyName);
        if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
            throw new \LogicException(
                "preg_replace_callback() callback [{$className}, {$methodName}] must be a user-defined static "
                .'method in this compile unit (#36382)'
            );
        }

        return $proxy;
    }

    private static function resolveStaticProxyForClass(
        Context $context,
        string $classLc,
        string $methodLc
    ): ?string {
        $visited = [];
        $current = $classLc;
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
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
