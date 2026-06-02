<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\RuntimeIndirectClosureCall;
use PHPLLVM\Value;

/**
 * JIT lowering for anonymous closures, including use() by-value and by-ref captures (issue #72).
 *
 * Direct calls use {@see Variable::$closureCall}. Indirect holders (array elements,
 * properties) resolve via {@see TARGET_PROPERTY} on the Closure object.
 */
final class ClosureHelper
{
    public const TARGET_PROPERTY = '__closure_target';

    private static int $counter = 0;

    public static function nextInternalName(): string
    {
        return '{closure}_'.(++self::$counter);
    }

    public static function resolveCall(Context $context, Variable $receiver): ?Call
    {
        if (null !== $receiver->closureCall) {
            return $receiver->closureCall;
        }

        return self::resolveIndirectCall($context, $receiver);
    }

    public static function allocateClosureObject(Context $context, Call $callProxy, string $internalName): Variable
    {
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        self::storeTargetName($context, $obj, $internalName);
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->closureCall = $callProxy;
        $context->lastClosureCallProxy = $callProxy;

        return $var;
    }

    /**
     * @return array<string, Call>
     */
    public static function closureCandidates(Context $context): array
    {
        $out = [];
        foreach ($context->functionProxies as $lc => $proxy) {
            if (str_starts_with($lc, '{closure}_')) {
                $out[$lc] = $proxy;
            }
        }
        ksort($out);

        return $out;
    }

    public static function loadObjectFromCallable(Context $context, Variable $callable): Value
    {
        if (Variable::TYPE_OBJECT === $callable->type) {
            return $context->helper->loadValue($callable);
        }
        if (Variable::TYPE_VALUE === $callable->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $callable)
            );
        }

        throw new \LogicException('Closure invoke requires an object callable');
    }

    public static function loadClassId(Context $context, Value $obj): Value
    {
        $map = $context->structFieldMap['__object__'];

        return $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );
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
    /** By-value snapshot of an enclosing variable at closure creation (issue #72). */
    public static function snapshotCapture(Context $context, Variable $src): Variable
    {
        $slot = JitValueBox::alloc($context);
        if (Variable::TYPE_VALUE === $src->type) {
            JitValueBox::copyFromPointer(
                $context,
                $slot,
                JitValueBox::valuePtrFromVariable($context, $src)
            );
        } else {
            $ptr = JitValueBox::pointer($context, $slot);
            switch ($src->type) {
                case Variable::TYPE_NATIVE_LONG:
                    JitValueBox::writeLong($context, $slot, $context->helper->loadValue($src));
                    break;
                case Variable::TYPE_NATIVE_DOUBLE:
                    $context->builder->call(
                        $context->lookupFunction('__value__writeDouble'),
                        $ptr,
                        $context->helper->loadValue($src)
                    );
                    break;
                case Variable::TYPE_NATIVE_BOOL:
                    JitValueBox::writeBool($context, $slot, $context->helper->loadValue($src));
                    break;
                case Variable::TYPE_NULL:
                    $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                    break;
                case Variable::TYPE_STRING:
                    $owned = $context->builder->call(
                        $context->lookupFunction('__string__separate'),
                        $context->helper->loadValue($src)
                    );
                    $context->builder->call(
                        $context->lookupFunction('__value__writeString'),
                        $ptr,
                        $owned
                    );
                    break;
                case Variable::TYPE_OBJECT:
                    $context->builder->call(
                        $context->lookupFunction('__value__writeObject'),
                        $ptr,
                        $context->helper->loadValue($src)
                    );
                    break;
                case Variable::TYPE_HASHTABLE:
                    $ht = $context->helper->loadValue($src);
                    $context->refcount->addref($ht);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeHashtable'),
                        $ptr,
                        $ht
                    );
                    break;
                default:
                    JitValueBox::copyFromPointer(
                        $context,
                        $slot,
                        JitValueBox::valuePtrFromVariable($context, $src)
                    );
            }
        }
        $dest = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        $dest->addref();

        return $dest;
    }

    /** By-reference bind to enclosing storage; resolved at each invoke (issue #72). */
    public static function referenceCapture(Context $context, Variable $src): Variable
    {
        $src->addref();
        JitValueBox::promoteNativeLvalueToValueBox($context, $src);
        if (null === $src->valueBoxAliasPtr) {
            $src->valueBoxAliasPtr = JitValueBox::valuePtrFromVariable($context, $src);
        }

        return $src;
    }

    /** Alias a closure capture slot to the capture formal {@see __value__*} (issue #72). */
    public static function bindCaptureSlotByReference(
        Context $context,
        Variable $captureSlot,
        Variable $captureArg
    ): void {
        $captureSlot->valueBoxAliasPtr = JitValueBox::valuePtrFromVariable($context, $captureArg);
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
            if (null === $src) {
                $captures[] = self::nullCapture($context);

                continue;
            }
            $captures[] = $spec['byRef']
                ? self::referenceCapture($context, $src)
                : self::snapshotCapture($context, $src);
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

    private static function resolveIndirectCall(Context $context, Variable $receiver): ?Call
    {
        if (Variable::TYPE_STRING === $receiver->type) {
            return null;
        }
        if (Variable::TYPE_VALUE === $receiver->type && null !== $receiver->compileTimeString) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $receiver->type && Variable::TYPE_VALUE !== $receiver->type) {
            return null;
        }
        $candidates = self::closureCandidates($context);
        if ([] === $candidates) {
            return null;
        }
        $classId = $context->type->object->lookup('Closure');

        return new RuntimeIndirectClosureCall($receiver, $candidates, $classId);
    }

    private static function storeTargetName(Context $context, Value $obj, string $internalName): void
    {
        $targetStr = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(strtolower($internalName)))
        );
        $targetStr->addref();
        $context->type->object->storeInstanceProperty(
            $obj,
            'Closure',
            self::TARGET_PROPERTY,
            $targetStr
        );
    }
}
