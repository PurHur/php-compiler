<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionProperty::isDefault() — declared non-virtual property (#22143).
 * php-src: zim_ReflectionProperty_isDefault — !(prop_info->flags & ZEND_ACC_VIRTUAL).
 */
final class ReflectionPropertyIsDefault extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDefault');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isDynamicReflectionProperty($receiver)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        $isVirtual = ReflectionPropertyHookSupport::isVirtual($entry, $meta, $property, $ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(!$isVirtual);
        }
    }
}
