<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\VM\VmClosure;
use PHPLLVM\Value;

/**
 * JIT trampoline for anonymous closures (#72, #10344).
 *
 * SSOT: {@see \PHPCompiler\VM\VmClosure}
 */
final class ClosureHelper
{
    public const TARGET_PROPERTY = VmClosure::TARGET_PROPERTY;

    public static function nextInternalName(): string
    {
        return VmClosure::nextInternalName();
    }

    public static function resolveCall(Context $context, Variable $receiver): ?Call
    {
        return VmClosure::resolveCall($context, $receiver);
    }

    public static function allocateClosureObject(Context $context, Call $callProxy, string $internalName): Variable
    {
        return VmClosure::allocateClosureObject($context, $callProxy, $internalName);
    }

    /**
     * @return array<string, Call>
     */
    public static function closureCandidates(Context $context): array
    {
        return VmClosure::closureCandidates($context);
    }

    public static function loadObjectFromCallable(Context $context, Variable $callable): Value
    {
        return VmClosure::loadObjectFromCallable($context, $callable);
    }

    public static function loadClassId(Context $context, Value $obj): Value
    {
        return VmClosure::loadClassId($context, $obj);
    }

    /**
     * @return list<int>
     */
    public static function orderedCaptureSlots(Block $block): array
    {
        return VmClosure::orderedCaptureSlots($block);
    }

    public static function operandForCaptureSlot(Block $block, int $slot): ?\PHPCfg\Operand
    {
        return VmClosure::operandForCaptureSlot($block, $slot);
    }

    public static function nullCapture(Context $context): Variable
    {
        return VmClosure::nullCapture($context);
    }

    public static function snapshotCapture(Context $context, Variable $src): Variable
    {
        return VmClosure::snapshotCapture($context, $src);
    }

    public static function referenceCapture(Context $context, Variable $src): Variable
    {
        return VmClosure::referenceCapture($context, $src);
    }

    public static function bindCaptureSlotByReference(
        Context $context,
        Variable $captureSlot,
        Variable $captureArg
    ): void {
        VmClosure::bindCaptureSlotByReference($context, $captureSlot, $captureArg);
    }

    /**
     * @param list<array{name: string, slot: int, byRef: bool}> $captureSpecs
     *
     * @return list<Variable>
     */
    public static function snapshotCapturesForClosure(
        Context $context,
        Block $closureBlock,
        array $captureSpecs
    ): array {
        return VmClosure::snapshotCapturesForClosure($context, $closureBlock, $captureSpecs);
    }

    public static function wrapCallWithCaptures(Call $inner, array $captures): Call
    {
        return VmClosure::wrapCallWithCaptures($inner, $captures);
    }
}
