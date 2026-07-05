<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringClassParents;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for class_parents() via ClassParentsJitHelper PHP (#3159, #16586).
 *
 * Compile-time literal class names keep registry fast path; runtime operands route through PHP.
 * php-src: ext/standard/class.c — PHP_FUNCTION(class_parents)
 */
final class JitClassParents
{
    public static function invoke(Context $context, JITVariable $whatArg, bool $autoload): Value
    {
        $compileTimeEnum = $whatArg->compileTimeEnumCase ?? null;
        if (\is_array($compileTimeEnum) && isset($compileTimeEnum['classId'])) {
            $object = $context->type->object;
            if ($object instanceof ObjectBuiltin) {
                return self::invokeForClassName(
                    $context,
                    $object->classNameForId((int) $compileTimeEnum['classId']),
                    $autoload
                );
            }
        }

        $literal = JitStringArg::compileTimeLiteral($whatArg);
        if (null !== $literal) {
            return self::invokeForClassName($context, $literal, $autoload);
        }

        return self::routeThroughPhpHelper($context, $whatArg, $autoload);
    }

    private static function routeThroughPhpHelper(
        Context $context,
        JITVariable $whatArg,
        bool $autoload
    ): Value {
        $operandPtr = self::operandToValueBox($context, $whatArg);
        $i1 = $context->getTypeFromString('int1');
        $autoloadVal = $autoload ? $i1->constInt(1, false) : $i1->constInt(0, false);

        return StringClassParents::invoke($context, $operandPtr, $autoloadVal);
    }

    private static function operandToValueBox(Context $context, JITVariable $whatArg): Value
    {
        if (JITVariable::TYPE_VALUE === $whatArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $whatArg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (JITVariable::TYPE_OBJECT === $whatArg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );
        } elseif (JITVariable::TYPE_STRING === $whatArg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );
        } else {
            throw new \LogicException(
                'class_parents() class name must be a string literal or object in this compiler build'
            );
        }

        return $ptr;
    }

    private static function invokeForClassName(Context $context, string $className, bool $autoload): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->isInterfaceClassLc($lc)) {
            return self::returnEmptyArray($context);
        }
        if ($object->hasUserDeclaredClass($className)) {
            return self::buildParentsFromObjectRegistry($context, $className);
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            $entry = $vm->classes[$lc];
            if ($entry->isTrait || $entry->isInterface || $entry->isEnum) {
                return self::returnEmptyArray($context);
            }

            return self::buildParentsFromNames(
                $context,
                VmReflection::classParentsList($entry, $vm)
            );
        }

        if (!$autoload) {
            return self::returnFalse($context);
        }

        return self::returnFalse($context);
    }

    private static function buildParentsFromObjectRegistry(Context $context, string $className): Value
    {
        $object = $context->type->object;
        $names = [];
        $current = $className;
        while (true) {
            $parent = $object->parentClassDisplayName($current);
            if (null === $parent) {
                break;
            }
            $names[] = $parent;
            $current = $parent;
        }

        return self::buildParentsFromNames($context, $names);
    }

    /**
     * @param list<string> $parentNames
     */
    private static function buildParentsFromNames(Context $context, array $parentNames): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($parentNames as $parentName) {
            $keyStr = $context->builder->load($context->constantStringFromString($parentName));
            $jit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($parentName))
            );
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function returnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }

    private static function returnEmptyArray(Context $context): Value
    {
        return self::buildParentsFromNames($context, []);
    }
}
