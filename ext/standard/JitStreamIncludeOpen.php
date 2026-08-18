<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamIncludeOpen;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared JIT lowering for script/include opens blocked by allow_url_include (#32104). */
final class JitStreamIncludeOpen
{
    /**
     * @return Value|null early-return value when blocked at compile time; null to continue
     */
    public static function rejectCompileTimeBlockedScriptOpen(
        Context $context,
        ?string $pathLit,
        string $function,
        bool $forHighlight,
        bool $returnFalseOnBlock
    ): ?Value {
        if (null === $pathLit || !VmStreamIncludeOpenPolicy::blockedForScriptOpen($pathLit, null)) {
            return null;
        }
        self::emitCompileTimeBlockedWarnings($context, $function, $pathLit, $forHighlight);

        return $returnFalseOnBlock
            ? self::materializeFalse($context)
            : self::materializeEmptyString($context);
    }

    /**
     * Emit runtime guard: if blocked → return $makeBlockedReturn(); else run $continue callback.
     *
     * @param callable(Context): Value $makeBlockedReturn
     * @param callable(Context): Value $continue
     */
    public static function wrapWithRuntimeBlockedGuard(
        Context $context,
        Value $pathStr,
        string $function,
        bool $forHighlight,
        callable $makeBlockedReturn,
        callable $continue
    ): Value {
        StreamIncludeOpen::ensureLinked($context);
        $i1 = $context->getTypeFromString('i1');
        $i32 = $context->getTypeFromString('int32');
        $blocked = $context->builder->call(
            StreamIncludeOpen::helperFunction($context),
            $pathStr,
            $context->builder->load($context->constantStringFromString($function)),
            $i32->constInt($forHighlight ? 1 : 0, false)
        );
        $isBlocked = $context->builder->icmp(Builder::INT_NE, $blocked, $i1->constInt(0, false));
        $blockedBb = BasicBlockHelper::append($context, $function.'_url_include_blocked');
        $okBb = BasicBlockHelper::append($context, $function.'_url_include_ok');
        $doneBb = BasicBlockHelper::append($context, $function.'_url_include_done');
        $context->builder->branchIf($isBlocked, $blockedBb, $okBb);

        $context->builder->positionAtEnd($blockedBb);
        $blockedVal = $makeBlockedReturn($context);
        $blockedEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $okVal = $continue($context);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($blockedVal, $blockedEnd);
        $phi->addIncoming($okVal, $okEnd);

        return $phi;
    }

    public static function materializeEmptyString(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString(''))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    public static function materializeFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }

    private static function emitCompileTimeBlockedWarnings(
        Context $context,
        string $function,
        string $path,
        bool $forHighlight
    ): void {
        self::emitWarning($context, VmStreamIncludeOpenPolicy::wrapperDisabledMessage($function, $path));
        self::emitWarning($context, VmStreamIncludeOpenPolicy::failedToOpenMessage($function, $path));
        if ($forHighlight) {
            self::emitWarning(
                $context,
                VmStreamOpenFailure::highlightFailedOpeningMessage($function, $path)
            );
        }
    }

    private static function emitWarning(Context $context, string $message): void
    {
        StringTriggerError::ensureLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
