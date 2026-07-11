<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringClassImplements;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for class_implements() via ClassImplementsJitHelper PHP (#3099, #16960).
 *
 * Compile-time literal class names keep registry fast path; boxed/value operands route through PHP.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_implements)
 */
final class JitClassImplements
{
    private static int $seq = 0;

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

        if (JITVariable::TYPE_OBJECT === $whatArg->type) {
            return self::invokeForObject($context, $whatArg, $autoload);
        }

        if (JITVariable::TYPE_VALUE === $whatArg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $whatArg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $objVar = new JITVariable(
                $context,
                JITVariable::TYPE_OBJECT,
                JITVariable::KIND_VALUE,
                $obj
            );

            return self::invokeForObject($context, $objVar, $autoload);
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

        return StringClassImplements::invoke($context, $operandPtr, $autoloadVal);
    }

    private static function operandToValueBox(Context $context, JITVariable $whatArg): Value
    {
        if (JITVariable::TYPE_VALUE === $whatArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $whatArg);
        }
        if (JITVariable::TYPE_STRING === $whatArg->type) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );

            return $ptr;
        }

        throw new \LogicException(
            'class_implements() operand must be a compile-time literal, object, or value box in this compiler build'
        );
    }

    private static function invokeForObject(
        Context $context,
        JITVariable $objectArg,
        bool $autoload
    ): Value {
        $obj = $context->helper->loadValue($objectArg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $object = $context->type->object;
        $names = $object->allClassNamesById();
        if ([] === $names) {
            return self::returnFalse($context);
        }

        $tag = 'ci_obj_'.(string) ++self::$seq;
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $falseBlock = BasicBlockHelper::append($context, $tag.'_false');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        /** @var list<array{0: \PHPLLVM\BasicBlock, 1: Value}> $incoming */
        $incoming = [];

        $ids = array_keys($names);
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => $id) {
            $className = $names[$id];
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $lastIdx === $idx
                ? $falseBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf($isClass, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            $ptr = self::invokeForClassName($context, $className, $autoload);
            $incoming[] = [$context->builder->getInsertBlock(), $ptr];
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($falseBlock);
        $falsePtr = self::returnFalse($context);
        $incoming[] = [$falseBlock, $falsePtr];
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($valuePtrTy);
        foreach ($incoming as [$block, $ptr]) {
            $result->addIncoming($ptr, $block);
        }

        return $result;
    }

    private static function invokeForClassName(Context $context, string $className, bool $autoload): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->isTraitClass($lc)) {
            return self::buildInterfaceMapFromNames($context, []);
        }
        if ($object->hasUserDeclaredClass($className)
            || $object->isInterfaceClassLc($lc)
            || $object->hasUserDeclaredEnum($className)) {
            return self::invokeForClassNameFromObjectRegistry($context, $className);
        }

        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            $entry = $vm->classes[$lc];
            if ($entry->isTrait) {
                return self::buildInterfaceMapFromNames($context, []);
            }

            return self::buildInterfaceMapFromNames(
                $context,
                array_values(VmReflection::classImplementsMap($entry, $vm))
            );
        }

        if (!$autoload) {
            return self::returnFalse($context);
        }

        return self::returnFalse($context);
    }

    private static function invokeForClassNameFromObjectRegistry(Context $context, string $className): Value
    {
        $object = $context->type->object;
        $classLc = strtolower(ltrim($className, '\\'));
        if (!$object->hasUserDeclaredClass($className)
            && !$object->isInterfaceClassLc($classLc)
            && !$object->hasUserDeclaredEnum($className)) {
            return self::returnFalse($context);
        }

        $names = [];
        $ifaceLcs = $object->interfacesForClassImplementsLc($classLc);
        foreach ($ifaceLcs as $ifaceLc) {
            $builtin = VmReflection::builtinEnumInterfaceDisplayName($ifaceLc);
            if (null !== $builtin) {
                $names[] = $builtin;
                continue;
            }
            foreach ($object->allClassNamesById() as $name) {
                if (strtolower(ltrim($name, '\\')) === $ifaceLc) {
                    $names[] = $name;
                    break;
                }
            }
        }
        if ([] === $names && [] !== $ifaceLcs) {
            foreach ($ifaceLcs as $ifaceLc) {
                $names[] = VmReflection::builtinEnumInterfaceDisplayName($ifaceLc) ?? $ifaceLc;
            }
        }
        if ($object->classHasImplicitStringableLc($classLc)) {
            $names[] = 'Stringable';
            $names = array_values(array_unique($names));
        }

        return self::buildInterfaceMapFromNames($context, $names);
    }

    /**
     * @param list<string> $ifaceNames
     */
    private static function buildInterfaceMapFromNames(Context $context, array $ifaceNames): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($ifaceNames as $ifaceName) {
            $keyStr = $context->builder->load($context->constantStringFromString($ifaceName));
            $val = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($ifaceName))
            );
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $val);
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
}
