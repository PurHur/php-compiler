<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\LazyGhostTraitSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM helpers for reflection / introspection builtins (#1214–#1219).
 */
final class ReflectionBuiltinHelper
{
    private static function objectBuiltin(Context $context): Object_
    {
        return $context->type->object;
    }

    public static function requireCompileTimeClassName(Context $context, Variable $arg, string $label): string
    {
        $name = JitStringArg::compileTimeLiteral($arg);
        if (null === $name) {
            throw new \LogicException("{$label} must be a string literal in this compiler build");
        }

        return $name;
    }

    public static function classExistsLiteral(Context $context, string $className): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = self::objectBuiltin($context);
        $exists = $object->hasUserDeclaredClass($className)
            && !$object->isTraitClass($lc)
            && !$object->isInterfaceClassLc($lc);
        if (!$exists && null !== $context->runtime->vmContext) {
            $entry = $context->runtime->vmContext->classes[$lc] ?? null;
            $exists = null !== $entry
                && !$entry->isInterface
                && !$entry->isTrait
                && !\PHPCompiler\VM\ResourceSupport::isHiddenPseudoClassEntry($entry);
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    /** interface_exists() — user interfaces only (Zend; php-src basic_functions.c). */
    public static function interfaceExistsLiteral(Context $context, string $interfaceName): Value
    {
        $lc = strtolower(ltrim($interfaceName, '\\'));
        $exists = self::objectBuiltin($context)->isInterfaceClassLc($lc);
        if (!$exists && null !== $context->runtime->vmContext) {
            $entry = $context->runtime->vmContext->classes[$lc] ?? null;
            $exists = null !== $entry && $entry->isInterface;
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    /** trait_exists() — user traits only (Zend; php-src basic_functions.c). */
    public static function traitExistsLiteral(Context $context, string $traitName): Value
    {
        if (LazyGhostTraitSupport::isLazyGhostTrait($traitName)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $lc = strtolower(ltrim($traitName, '\\'));
        $exists = self::objectBuiltin($context)->isTraitClass($lc);
        if (!$exists && null !== $context->runtime->vmContext) {
            $entry = $context->runtime->vmContext->classes[$lc] ?? null;
            $exists = null !== $entry && $entry->isTrait;
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function enumExistsLiteral(Context $context, string $enumName): Value
    {
        $lc = strtolower($enumName);
        $exists = self::objectBuiltin($context)->hasUserDeclaredEnum($enumName)
            || (null !== $context->runtime->vmContext && isset($context->runtime->vmContext->enums[$lc]));
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function unitEnumExistsLiteral(Context $context, string $enumName): Value
    {
        $object = self::objectBuiltin($context);
        $lc = strtolower($enumName);
        $exists = false;
        if ($object->hasUserDeclaredEnum($enumName)) {
            $classId = $object->lookup($enumName);
            $exists = !$object->enumHasBacking($classId);
        } elseif (null !== $context->runtime->vmContext) {
            $entry = $context->runtime->vmContext->classes[$lc] ?? null;
            $exists = null !== $entry && $entry->isEnum && null === $entry->backedType;
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function functionExistsLiteral(Context $context, string $functionName): Value
    {
        $lc = strtolower($functionName);
        $exists = isset($context->runtime->vmContext->functions[$lc])
            || isset($context->functions[$lc]);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function methodExistsLiteral(Context $context, string $className, string $method): Value
    {
        $object = self::objectBuiltin($context);
        $lc = strtolower(ltrim($className, '\\'));
        $exists = false;
        if ($object->hasUserDeclaredClass($className) || $object->isInterfaceClassLc($lc)) {
            $classId = $object->lookup($className);
            $exists = $object->hasMethod($classId, $method);
        } elseif (null !== $context->runtime->vmContext && isset($context->runtime->vmContext->classes[$lc])) {
            $exists = \PHPCompiler\ext\standard\VmReflection::methodExistsOnClass(
                $context->runtime->vmContext->classes[$lc],
                $method
            );
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    /** class_has_method() — ReflectionClass::hasMethod semantics (#9989). */
    public static function classHasMethodLiteral(Context $context, string $className, string $method): Value
    {
        $ctx = $context->runtime->vmContext;
        $exists = false;
        if (null !== $ctx) {
            $lc = strtolower(ltrim($className, '\\'));
            $entry = $ctx->classes[$lc] ?? null;
            $exists = null !== $entry
                && \PHPCompiler\ext\standard\VmReflection::classHasMethodForReflection($entry, $ctx, $method);
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    /** class_has_property() — ReflectionClass::hasProperty semantics (#9989). */
    public static function classHasPropertyLiteral(Context $context, string $className, string $property): Value
    {
        $ctx = $context->runtime->vmContext;
        $exists = false;
        if (null !== $ctx) {
            $lc = strtolower(ltrim($className, '\\'));
            $entry = $ctx->classes[$lc] ?? null;
            $exists = null !== $entry
                && \PHPCompiler\ext\standard\VmReflection::classHasPropertyForReflection($entry, $ctx, $property);
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    /** class_has_constant() — ReflectionClass::hasConstant semantics (#9989). */
    public static function classHasConstantLiteral(Context $context, string $className, string $constant): Value
    {
        $ctx = $context->runtime->vmContext;
        $exists = false;
        if (null !== $ctx) {
            $lc = strtolower(ltrim($className, '\\'));
            $entry = $ctx->classes[$lc] ?? null;
            $exists = null !== $entry
                && \PHPCompiler\ext\standard\VmReflection::classHasConstantForReflection($entry, $ctx, $constant);
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function propertyExistsLiteral(Context $context, string $className, string $property): Value
    {
        $ctx = $context->runtime->vmContext;
        if (null !== $ctx) {
            $lc = strtolower(ltrim($className, '\\'));
            $entry = $ctx->classes[$lc] ?? null;
            $exists = null !== $entry
                && \PHPCompiler\ext\standard\VmReflection::propertyExistsOnClass($entry, $property, $ctx);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt($exists ? 1 : 0, false);
        }
        $object = self::objectBuiltin($context);
        if (!$object->hasUserDeclaredClass($className)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classId = $object->lookup($className);
        $exists = $object->propertyExistsFromScope($classId, $property);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    /** attribute_exists() — class declares attribute at compile time (#6468). */
    public static function attributeExistsLiteral(Context $context, string $className, string $attributeName): Value
    {
        $exists = false;
        $ctx = $context->runtime->vmContext;
        if (null !== $ctx) {
            $lc = strtolower(ltrim($className, '\\'));
            $entry = $ctx->classes[$lc] ?? null;
            if (null !== $entry) {
                foreach ($entry->attributeEntries as $attrEntry) {
                    if (self::attributeNameMatches($attrEntry->name, $attributeName)) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    foreach ($entry->attributeNames as $name) {
                        if (self::attributeNameMatches($name, $attributeName)) {
                            $exists = true;
                            break;
                        }
                    }
                }
            }
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    private static function attributeNameMatches(string $candidate, string $filter): bool
    {
        $want = strtolower(ltrim($filter, '\\'));
        $cand = strtolower(ltrim($candidate, '\\'));

        return $cand === $want || str_ends_with($cand, '\\'.$want);
    }

    public static function emitInstanceOf(Context $context, Variable $value, string $className): Variable
    {
        return self::objectBuiltin($context)->emitInstanceOf($value, $className);
    }

    public static function emitSubclassOf(Context $context, Variable $value, string $className): Variable
    {
        return self::objectBuiltin($context)->emitSubclassOf($value, $className);
    }

    public static function classIsInstanceOfLiteral(Context $context, string $childName, string $parentName): Value
    {
        $match = self::objectBuiltin($context)->classIsInstanceOf($childName, $parentName);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($match ? 1 : 0, false);
    }

    public static function classIsSubclassOfLiteral(Context $context, string $childName, string $parentName): Value
    {
        $match = self::objectBuiltin($context)->classIsSubclassOf($childName, $parentName);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($match ? 1 : 0, false);
    }

    public static function getClassName(Context $context, Variable $object): Value
    {
        if (Variable::TYPE_OBJECT !== $object->type && Variable::TYPE_VALUE !== $object->type) {
            throw new \LogicException('get_class() argument must be an object in this compiler build');
        }
        $objBuiltin = self::objectBuiltin($context);
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        if (Variable::TYPE_OBJECT === $object->type) {
            $obj = $context->helper->loadValue($object);
            $classId = $context->builder->load(
                $context->builder->structGep($obj, $objMap['class_id'])
            );

            return self::classNameFromId($context, $classId);
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $object);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $obj,
            $objType->constNull()
        );
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $nameWhenObject = self::classNameFromId($context, $classId);
        $falseStr = $context->builder->load($context->constantStringFromString(''));

        return $context->builder->select($isObject, $nameWhenObject, $falseStr);
    }

    public static function classNameStringFromClassId(Context $context, Value $classId): Value
    {
        return self::classNameFromId($context, $classId);
    }

    private static function classNameFromId(Context $context, Value $classId): Value
    {
        GetClassRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__phpc_class_name_from_id'),
            $classId
        );
    }
}
