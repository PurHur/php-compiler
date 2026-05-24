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
use PHPLLVM\Value;

/** LLVM lowering for preg_replace_callback() via __compiler_preg_replace_callback (issue #1177). */
final class JitPregReplaceCallback
{
    private static int $blockSerial = 0;

    /** @return Value __value__* (string result, or boolean false on PCRE error) */
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
        $valuePtr = $context->getTypeFromString('__value__*');
        $callbackFnTy = $context->context->functionType($valuePtr, false, $valuePtr);
        $callbackPtr = $context->builder->pointerCast(
            $proxy->function,
            $callbackFnTy->pointerType(0)
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_replace_callback'),
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
}
