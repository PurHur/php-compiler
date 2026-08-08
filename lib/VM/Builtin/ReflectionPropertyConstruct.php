<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::__construct($class, $property) — VM (#3354, #28939). */
final class ReflectionPropertyConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 2) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionProperty', 2, $argc);
        }
        $ctx = VmReflection::requireContext($frame);
        $classArg = $frame->calledArgs[1]->resolveIndirect();
        $entry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $property = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionProperty::__construct() property', 2);
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        $enumPseudo = VmReflection::isEnumReflectionPseudoProperty($entry, $property);
        $isDynamic = false;
        if (null === $instanceName && null === $staticKey && !$enumPseudo) {
            if (Variable::TYPE_OBJECT === $classArg->type
                && VmReflection::isRuntimeDynamicProperty($classArg, $property, $entry, $ctx)
            ) {
                $isDynamic = true;
            } else {
                ReflectionSupport::throwReflectionException(
                    ReflectionSupport::propertyNotFoundMessage($entry->name, $property)
                );
            }
        }
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $declaringClassName = VmReflection::declaringClassNameForPropertyLookup($entry, $property, $ctx);
        // Zend ReflectionProperty::$name / $class (#22504) — not class-on-$name + internal `property`.
        $receiver->getProperty(ReflectionSupport::PROP_PROPERTY_NAME)->string($property);
        $receiver->getProperty(ReflectionSupport::PROP_DECLARING_CLASS_NAME)->string($declaringClassName);
        $receiver->getProperty(ReflectionSupport::PROP_IS_DYNAMIC)->bool($isDynamic);
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionProperty()` result slot (#6983).
    }
}
