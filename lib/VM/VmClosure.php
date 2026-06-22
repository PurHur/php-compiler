<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\RuntimeIndirectClosureCall;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Value;

/**
 * SSOT for JIT closure create/call lowering (#72, #10344).
 *
 * php-src: Zend/zend_closures.c — zend_create_closure, bind, static flag
 * php-src: Zend/zend_compile.c — closure opcodes
 *
 * VM runtime: {@see ClosureSupport}
 */
final class VmClosure
{
    public const TARGET_PROPERTY = '__closure_target';

    private static int $counter = 0;

    public static function nextInternalName(): string
    {
        return '{closure}_'.(++self::$counter);
    }

    public static function resolveCall(Context $context, JitVariable $receiver): ?Call
    {
        if (null !== $receiver->closureCall) {
            return $receiver->closureCall;
        }

        return self::resolveIndirectCall($context, $receiver);
    }

    public static function allocateClosureObject(Context $context, Call $callProxy, string $internalName): JitVariable
    {
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        self::storeTargetName($context, $obj, $internalName);
        $var = new JitVariable($context, JitVariable::TYPE_OBJECT, JitVariable::KIND_VALUE, $obj);
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

    public static function loadObjectFromCallable(Context $context, JitVariable $callable): Value
    {
        if (JitVariable::TYPE_OBJECT === $callable->type) {
            return $context->helper->loadValue($callable);
        }
        if (JitVariable::TYPE_VALUE === $callable->type) {
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

    public static function nullCapture(Context $context): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $var->isNullConstant = true;
        $var->addref();

        return $var;
    }

    /** By-value snapshot of an enclosing variable at closure creation (issue #72). */
    public static function snapshotCapture(Context $context, JitVariable $src): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $srcPtr = JitValueBox::valuePtrFromVariable($context, $src);
        JitValueBox::copyIntoPointer($context, JitValueBox::pointer($context, $slot), $srcPtr);
        $dest = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $dest->addref();

        return $dest;
    }

    /** By-reference bind to enclosing storage; resolved at each invoke (issue #72). */
    public static function referenceCapture(Context $context, JitVariable $src): JitVariable
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
        JitVariable $captureSlot,
        JitVariable $captureArg
    ): void {
        $captureSlot->valueBoxAliasPtr = JitValueBox::valuePtrFromVariable($context, $captureArg);
        $captureSlot->type = JitVariable::TYPE_VALUE;
        $captureSlot->kind = JitVariable::KIND_VARIABLE;
    }

    /**
     * @param list<array{name: string, slot: int, byRef: bool}> $captureSpecs
     *
     * @return list<JitVariable>
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

    private static function resolveIndirectCall(Context $context, JitVariable $receiver): ?Call
    {
        if (JitVariable::TYPE_STRING === $receiver->type) {
            return null;
        }
        if (JitVariable::TYPE_VALUE === $receiver->type && null !== $receiver->compileTimeString) {
            return null;
        }
        if (JitVariable::TYPE_OBJECT !== $receiver->type && JitVariable::TYPE_VALUE !== $receiver->type) {
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
        $targetStr = new JitVariable(
            $context,
            JitVariable::TYPE_STRING,
            JitVariable::KIND_VALUE,
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
