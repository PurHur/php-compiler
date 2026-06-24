<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\VM\MagicMethodJitHelper;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * LLVM lowering for user magic methods __get / __set / __call / __toString (#146, #4022, #10201).
 *
 * php-src: Zend/zend_object_handlers.c — zend_std_read_property, zend_std_write_property,
 * zend_std_get_method, zend_std_cast_object_tostring.
 */
final class MagicMethodLlvm
{
    public static function hasInstanceMethod(Object_ $object, int $classId, string $methodLc): bool
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $classLc = strtolower(ltrim($object->classNameForId($classId), '\\'));
        while (!isset($visited[$classLc])) {
            $visited[$classLc] = true;
            $id = $object->lookup($classLc);
            if ($object->hasMethod($id, $methodLc)) {
                return true;
            }
            $parentLc = $object->parentClassLc($classLc);
            if (null === $parentLc) {
                return false;
            }
            $classLc = $parentLc;
        }

        return false;
    }

    public static function resolveInstanceMethodProxy(
        Context $context,
        string $classLc,
        string $methodLc
    ): ?string {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Compile-time mirror of VM propertyReadUsesMagicGet (#4673).
     */
    public static function propertyReadUsesMagicGetAtCompileTime(
        Context $context,
        int $classId,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): bool {
        $object = $context->type->object;
        $hasGet = self::hasInstanceMethod($object, $classId, '__get');
        $hasProperty = $object->hasProperty($classId, $propertyName);
        if (!$hasGet) {
            return MagicMethodJitHelper::propertyReadUsesMagicGet(false, $hasProperty, false, false);
        }
        if (!$hasProperty) {
            return MagicMethodJitHelper::propertyReadUsesMagicGet(true, false, false, false);
        }
        $callerClassLc = null;
        if (null !== $enclosingBlock?->func?->class) {
            $callerClassLc = strtolower($enclosingBlock->func->class->value);
        }
        $visibility = $object->propertyVisibility($classId, $propertyName);
        $isPublic = MethodVisibility::isPublic($visibility);
        if ($isPublic) {
            return MagicMethodJitHelper::propertyReadUsesMagicGet(true, true, true, false);
        }
        $declaringClassLc = strtolower(ltrim($declaringClass, '\\'));
        $objectClassLc = strtolower(ltrim($object->classNameForId($classId), '\\'));
        $visibilityDenied = false;
        try {
            PropertyVisibility::assertAccessible(
                $visibility,
                $callerClassLc,
                $declaringClassLc,
                $declaringClass,
                $propertyName,
                $objectClassLc,
                fn (string $classLc, string $ancestorLc): bool => $object->classIsSubclassOf($classLc, $ancestorLc)
                    || $classLc === $ancestorLc
            );
        } catch (\LogicException $e) {
            $visibilityDenied = true;
        }

        return MagicMethodJitHelper::propertyReadUsesMagicGet(
            true,
            true,
            false,
            $visibilityDenied
        );
    }

    public static function emitMagicGetIndirectModifyError(Context $context, string $className, string $propertyName): void
    {
        ErrorRaise::emitRaise(
            $context,
            sprintf(
                'Indirect modification of overloaded property %s::$%s has no effect',
                $className,
                $propertyName
            )
        );
    }

    /**
     * @return Value|null lowered __get return value
     */
    public static function tryEmitMagicGet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        $classId = $context->type->object->lookup($declaringClass);
        if (!self::propertyReadUsesMagicGetAtCompileTime($context, $classId, $declaringClass, $propertyName, $enclosingBlock)) {
            return null;
        }
        if (!self::hasInstanceMethod($context->type->object, $classId, '__get')) {
            return null;
        }
        $proxy = self::resolveInstanceMethodProxy($context, $declaringClass, '__get');
        if (null === $proxy) {
            return null;
        }
        $receiverVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $receiver
        );
        $nameVar = self::stringVariable($context, $propertyName);
        $toCall = $context->resolveFunctionProxy($proxy);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $result = $toCall->call($context, $receiverVar, $nameVar);
        $context->callerStrictTypes = $prevStrict;

        return $result;
    }

    /**
     * @return Value|null lowered __get return value for a runtime property name (`$obj->$name`)
     */
    public static function tryEmitMagicGetDynamic(
        Context $context,
        Value $receiver,
        string $declaringClass,
        Value $nameStr,
        ?Block $enclosingBlock
    ): ?Value {
        $classId = $context->type->object->lookup($declaringClass);
        if (!self::hasInstanceMethod($context->type->object, $classId, '__get')) {
            return null;
        }
        $proxy = self::resolveInstanceMethodProxy($context, $declaringClass, '__get');
        if (null === $proxy) {
            return null;
        }
        $receiverVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $receiver
        );
        $nameArg = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $nameStr
        );
        $toCall = $context->resolveFunctionProxy($proxy);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $result = $toCall->call($context, $receiverVar, $nameArg);
        $context->callerStrictTypes = $prevStrict;

        return $result;
    }

    public static function tryEmitMagicSet(
        Context $context,
        Variable $receiver,
        string $propertyName,
        Variable $value,
        ?Block $enclosingBlock
    ): bool {
        $classLc = $receiver->objectPropertyClassName
            ?? ($context->scope->className !== '' ? $context->scope->className : 'object');
        $classId = $context->type->object->lookup($classLc);
        if ($context->type->object->hasProperty($classId, $propertyName)) {
            return false;
        }
        if (!self::hasInstanceMethod($context->type->object, $classId, '__set')) {
            return false;
        }
        $proxy = self::resolveInstanceMethodProxy($context, $classLc, '__set');
        if (null === $proxy) {
            return false;
        }
        $nameVar = self::stringVariable($context, $propertyName);
        $toCall = $context->resolveFunctionProxy($proxy);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $toCall->call($context, $receiver, $nameVar, $value);
        $context->callerStrictTypes = $prevStrict;

        return true;
    }

    public static function tryInitMagicCall(
        Context $context,
        string $declaringClassLc,
        string $methodName,
        Variable $receiverVar
    ): bool {
        $proxy = self::resolveInstanceMethodProxy($context, $declaringClassLc, '__call');
        if (null === $proxy) {
            return false;
        }
        $context->scope->magicCallMethodName = $methodName;
        $context->scope->toCall = $context->resolveFunctionProxy($proxy);
        $context->scope->args = [$receiverVar];

        return true;
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}|null
     */
    public static function rewriteOutgoingMagicCallArgs(
        Context $context,
        string $methodName,
        array $argEntries,
        array $argOperands
    ): ?array {
        if ([] === $argEntries) {
            return null;
        }
        $receiver = $argEntries[0];
        if (!($receiver instanceof Variable)) {
            return null;
        }
        $userEntries = \array_slice($argEntries, 1);
        $userOperands = \array_slice($argOperands, 1);
        $packed = [];
        $packedOperands = [];
        foreach ($userEntries as $i => $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                return null;
            }
            if (\is_array($entry) && isset($entry['named'])) {
                return null;
            }
            if ($entry instanceof Variable) {
                $packed[] = $entry;
                $packedOperands[] = $userOperands[$i] ?? null;
            }
        }
        $nameVar = self::stringVariable($context, $methodName);
        $argsVar = self::packPositionalArgs($context, $packed);

        return [
            [$receiver, $nameVar, $argsVar],
            [$argOperands[0] ?? null, null, null],
        ];
    }

    /**
     * Coerce an object to string via __toString when defined (echo / cast / concat).
     */
    public static function coerceObjectToString(Context $context, Variable $objectVar, ?string $className = null): ?Variable
    {
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            return null;
        }
        $className ??= $objectVar->type?->userType ?? '';
        if ('' === $className || 'object' === strtolower(ltrim($className, '\\'))) {
            return null;
        }
        $classId = $context->type->object->lookup($className);
        if (!self::hasInstanceMethod($context->type->object, $classId, '__tostring')) {
            return null;
        }
        $proxy = self::resolveInstanceMethodProxy($context, $className, '__tostring');
        if (null === $proxy) {
            return null;
        }
        $toCall = $context->resolveFunctionProxy($proxy);
        $raw = $toCall->call($context, $objectVar);
        $strPtr = (new \PHPCompiler\ext\standard\strval())->valueToString(
            $context,
            JitValueBox::coerceToValuePtrForStore($context, $raw)
        );

        return new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $strPtr
        );
    }

    /**
     * @param list<Variable> $args
     */
    private static function packPositionalArgs(Context $context, array $args): Variable
    {
        $slot = JitValueBox::alloc($context);
        $array = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        HashTableHelper::initArray($context, $array);
        foreach ($args as $arg) {
            HashTableHelper::addElement($context, $array, $arg);
        }

        return $array;
    }

    private static function stringVariable(Context $context, string $value): Variable
    {
        $lit = new Operand\Literal($value);
        $lit->type = \PHPTypes\Type::string();

        return Variable::fromLiteral($context, $lit);
    }
}
