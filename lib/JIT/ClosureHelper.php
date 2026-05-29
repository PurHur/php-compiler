<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\ClosureWithCaptures;

/**
 * JIT lowering for anonymous closures, including use() by-value captures (issue #72).
 *
 * Compiles the closure CFG as a native function and wraps the result in a Closure
 * object whose {@see Variable::$closureCall} proxy handles direct / __invoke calls.
 */
final class ClosureHelper
{
    private static int $counter = 0;

    public static function nextInternalName(): string
    {
        return '{closure}_'.(++self::$counter);
    }

    public static function resolveCall(Variable $receiver): ?Call
    {
        return $receiver->closureCall;
    }

    public static function allocateClosureObject(Context $context, Call $callProxy): Variable
    {
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->closureCall = $callProxy;

        return $var;
    }

    /**
     * @return list<int>
     */
    public static function orderedCaptureSlots(Block $block): array
    {
        $slots = array_keys($block->closureCaptureSlots);
        sort($slots, SORT_NUMERIC);

        return $slots;
    }

    public static function operandForCaptureSlot(Block $block, int $slot): ?\PHPCfg\Operand
    {
        return $block->operandForScopeSlot($slot);
    }

    public static function nullCapture(Context $context): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        $var->isNullConstant = true;
        $var->addref();

        return $var;
    }

    /** By-value snapshot of an enclosing variable at closure creation (issue #72). */
    public static function snapshotCapture(Context $context, Variable $src): Variable
    {
        $slot = JitValueBox::alloc($context);
        $srcPtr = JitValueBox::valuePtrFromVariable($context, $src);
        JitValueBox::copyFromPointer($context, $slot, $srcPtr);
        $dest = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        $dest->addref();

        return $dest;
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
        $specsBySlot = [];
        foreach ($captureSpecs as $spec) {
            if ($spec['byRef']) {
                throw new \LogicException('Closure use (&$x) not supported in JIT yet (issue #72)');
            }
            $specsBySlot[$spec['slot']] = $spec;
        }

        $captures = [];
        foreach (self::orderedCaptureSlots($closureBlock) as $slot) {
            $spec = $specsBySlot[$slot] ?? null;
            if (null === $spec) {
                $captures[] = self::nullCapture($context);

                continue;
            }
            $src = $context->variableForScopedName($spec['name']);
            $captures[] = null !== $src
                ? self::snapshotCapture($context, $src)
                : self::nullCapture($context);
        }

        return $captures;
    }

    public static function wrapCallWithCaptures(Call $inner, array $captures): Call
    {
        if ([] === $captures) {
            return $inner;
        }
        if (!$inner instanceof Call\Native) {
            throw new \LogicException('Closure with use() requires a Native call proxy');
        }

        return new ClosureWithCaptures($inner, $captures);
    }
}
