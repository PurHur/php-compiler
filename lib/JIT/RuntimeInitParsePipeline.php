<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Value;

/**
 * C-floor Runtime::initParsePipeline for M5 argv / gen-0 seed (#26756).
 *
 * Host-lowering the PHP CFG NestedJITs every visitor ctor and hung Zend rebuilds
 * for hours. Mirror {@see RuntimeInitCompiler}: allocate + markConstructed for
 * parse-spine fields without NestedJIT of Runtime.php.
 */
final class RuntimeInitParsePipeline
{
    public static function emit(Context $context, ObjectType $object, Value $runtimeThis): void
    {
        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'parser',
            self::allocConstructed($object, 'PHPCfg\\Parser')
        );
        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'preprocessor',
            self::allocConstructed($object, 'PHPCfg\\Traverser')
        );
        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'postprocessor',
            self::allocConstructed($object, 'PHPCfg\\Traverser')
        );
        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'detector',
            self::allocConstructed($object, 'PHPCompiler\\NullSafeLivenessDetector')
        );
        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'assignOpResolver',
            self::allocConstructed($object, 'PHPCompiler\\VM\\Optimizer\\AssignOp')
        );
        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'typeReconstructor',
            self::allocConstructed($object, 'PHPCompiler\\PHPTypes\\CompilerTypeReconstructor')
        );

        foreach ([
            'confusableBuiltinTypeHintCheck' => 'PHPCompiler\\Ast\\ConfusableBuiltinTypeHintCheck',
            'abstractEnumMarker' => 'PHPCompiler\\Ast\\AbstractEnumMarker',
            'sealedClassAnnotator' => 'PHPCompiler\\Ast\\SealedClassAnnotator',
            'staticClassAnnotator' => 'PHPCompiler\\Ast\\StaticClassAnnotator',
        ] as $prop => $class) {
            self::storeObjectProp(
                $context,
                $object,
                $runtimeThis,
                $prop,
                self::allocConstructed($object, $class)
            );
        }
    }

    private static function allocConstructed(ObjectType $object, string $class): Value
    {
        $id = $object->lookup($class);
        $obj = $object->allocate($id);
        $object->markObjectConstructed($obj);

        return $obj;
    }

    private static function storeObjectProp(
        Context $context,
        ObjectType $object,
        Value $runtimeThis,
        string $prop,
        Value $value
    ): void {
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $value);
        $slot = $object->propertyFetch($runtimeThis, 'PHPCompiler\\Runtime', $prop);
        $object->propertyStore($slot->objectPropertySlot, $var, Variable::TYPE_OBJECT);
    }
}
