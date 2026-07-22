<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionPropertyHookSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::setHook(PropertyHookType, Closure) — VM (#22116, ext/reflection/php_reflection.c). */
final class ReflectionPropertySetHook extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setHook');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionProperty::setHook() expects PropertyHookType and Closure');
        }
        [$ctx, $entry, $meta, $className, $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isDynamicReflectionProperty($receiver)) {
            throw new \Error('Cannot set hook on dynamic property');
        }
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        if (null !== $staticKey) {
            throw new \Error('Cannot set hook on static property');
        }
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        $hookKind = ReflectionPropertyHookSupport::parsePropertyHookTypeArg(
            $frame->calledArgs[1],
            'ReflectionProperty::setHook'
        );
        $closureVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $closureVar->type || null === $closureVar->toObject()->closureState) {
            throw new \TypeError(
                'ReflectionProperty::setHook(): Argument #2 ($hook) must be of type Closure, '
                .EnumCaseSupport::typeNameForVariable($closureVar).' given'
            );
        }
        $state = ClosureSupport::requireClosureState(
            $closureVar->toObject(),
            'ReflectionProperty::setHook()'
        );
        ReflectionPropertyHookSupport::installRuntimeHook(
            $ctx,
            $entry,
            $meta,
            $property,
            $hookKind,
            $state
        );
    }
}
