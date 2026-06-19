<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::__construct($class, $name) — VM (#3354). */
final class ReflectionPropertyConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionProperty::__construct() expects class and property name');
        }
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $property = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionProperty::__construct() name', 2);
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        $enumPseudo = VmReflection::isEnumReflectionPseudoProperty($entry, $property);
        if (null === $instanceName && null === $staticKey && !$enumPseudo) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($entry->name, $property)
            );
        }
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $declaringClassName = VmReflection::declaringClassNameForPropertyLookup($entry, $property, $ctx);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);
        $receiver->getProperty(ReflectionSupport::PROP_PROPERTY_NAME)->string($property);
        $receiver->getProperty(ReflectionSupport::PROP_DECLARING_CLASS_NAME)->string($declaringClassName);
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionProperty()` result slot (#6983).
    }
}
