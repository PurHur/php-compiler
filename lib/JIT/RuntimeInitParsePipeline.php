<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Value;

/**
 * C-floor Runtime::initParsePipeline for M5 argv / gen-0 seed (#26756 / #27426).
 *
 * Host-lowering the PHP CFG NestedJITs every visitor ctor and hung Zend rebuilds
 * for hours. Mirror {@see RuntimeInitCompiler}: allocate + markConstructed for
 * parse-spine fields without NestedJIT of Runtime.php.
 *
 * PHPCfg\Parser::parse (FORCE_PARSER NestedJIT) reads `$this->astParser`. A bare
 * allocConstructed Parser left that null and SIGABRT'd (#27426). Prior attempt to
 * allocate PhpParser\Parser\Php7 peers SEGVd the M5 argv rebuild at
 * c:main_before_php — Php7 is a 177KB generated parser; class registration during
 * emit poisons later includes. Wire a lightweight {@see M5ParserAstPeer} instead so
 * the property is a real object with hand-built {@see M5ParserAstPeer::parse} for
 * limited shapes; NestedJIT of real Php7 remains follow-up for full PHP.
 */
final class RuntimeInitParsePipeline
{
    public static function emit(Context $context, ObjectType $object, Value $runtimeThis): void
    {
        $parser = self::allocConstructed($object, 'PHPCfg\\Parser');
        // Lightweight peers — not PhpParser\Parser\Php7 (#27426 SEGV bisect).
        self::storeClassProp(
            $context,
            $object,
            $parser,
            'PHPCfg\\Parser',
            'astParser',
            self::allocConstructed($object, M5ParserAstPeer::class)
        );
        self::storeClassProp(
            $context,
            $object,
            $parser,
            'PHPCfg\\Parser',
            'astTraverser',
            self::allocConstructed($object, M5ParserAstPeer::class)
        );
        self::storeClassProp(
            $context,
            $object,
            $parser,
            'PHPCfg\\Parser',
            'magicStringResolver',
            self::allocConstructed($object, M5ParserAstPeer::class)
        );

        self::storeObjectProp(
            $context,
            $object,
            $runtimeThis,
            'parser',
            $parser
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

        // Skip prepare list-unpack in host-lowered Runtime::parse (#26756 SEGV).
        $i1 = $context->getTypeFromString('int1');
        $true = $i1->constInt(1, false);
        $flagVar = new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $true);
        $flagSlot = $object->propertyFetch($runtimeThis, 'PHPCompiler\\Runtime', 'm5ArgvIdentityParsePrepare');
        $object->propertyStore($flagSlot->objectPropertySlot, $flagVar, Variable::TYPE_NATIVE_BOOL);
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
        self::storeClassProp($context, $object, $runtimeThis, 'PHPCompiler\\Runtime', $prop, $value);
    }

    private static function storeClassProp(
        Context $context,
        ObjectType $object,
        Value $receiver,
        string $class,
        string $prop,
        Value $value
    ): void {
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $value);
        $slot = $object->propertyFetch($receiver, $class, $prop);
        $object->propertyStore($slot->objectPropertySlot, $var, Variable::TYPE_OBJECT);
    }
}
