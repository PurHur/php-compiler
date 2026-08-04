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
        // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338, #26863).
        if ('simplemxml_element' === $current) {
            $current = 'simplexmlelement';
        }
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
        $context->scope->magicCallIsStatic = false;
        $context->scope->toCall = $context->resolveFunctionProxy($proxy);
        $context->scope->args = [$receiverVar];

        return true;
    }

    /**
     * Bind a missing static call to `__callStatic` (Zend zend_std_get_static_method, #23336).
     */
    public static function tryInitMagicCallStatic(
        Context $context,
        string $declaringClassLc,
        string $methodName
    ): bool {
        $proxy = self::resolveInstanceMethodProxy($context, $declaringClassLc, '__callstatic');
        if (null === $proxy) {
            return false;
        }
        $context->scope->magicCallMethodName = $methodName;
        $context->scope->magicCallIsStatic = true;
        $context->scope->toCall = $context->resolveFunctionProxy($proxy);
        $context->scope->args = [];

        return true;
    }

    /**
     * Rewrite `__call` / `__callStatic` outgoing args to `($this?, $name, $arguments)`.
     *
     * Named args and compile-time named unpacks are packed into `$arguments` with
     * string keys preserved (Zend zend_object_handlers.c / #23336). Runtime unpack
     * that cannot be folded returns null so the caller keeps a non-magic path.
     *
     * Static vs instance is taken from {@see Scope::$magicCallIsStatic} — do not infer
     * from whether `$argEntries[0]` is a Variable (user args are Variables; #27517).
     *
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
        if ($context->scope->magicCallIsStatic) {
            return self::rewriteOutgoingMagicCallArgsStatic($context, $methodName, $argEntries, $argOperands);
        }
        if ([] === $argEntries) {
            return null;
        }
        $receiver = $argEntries[0];
        if (!($receiver instanceof Variable)) {
            return null;
        }
        $userEntries = \array_slice($argEntries, 1);
        $userOperands = \array_slice($argOperands, 1);
        $argsVar = self::packMagicUserArgs($context, $userEntries, $userOperands);
        if (null === $argsVar) {
            return null;
        }
        $nameVar = self::stringVariable($context, $methodName);

        return [
            [$receiver, $nameVar, $argsVar],
            [$argOperands[0] ?? null, null, null],
        ];
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}|null
     */
    private static function rewriteOutgoingMagicCallArgsStatic(
        Context $context,
        string $methodName,
        array $argEntries,
        array $argOperands
    ): ?array {
        $argsVar = self::packMagicUserArgs($context, $argEntries, $argOperands);
        if (null === $argsVar) {
            return null;
        }
        $nameVar = self::stringVariable($context, $methodName);

        return [
            [$nameVar, $argsVar],
            [null, null],
        ];
    }

    /**
     * Pack user call args into `__call`/`__callStatic` `$arguments` (named keys preserved).
     *
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $userEntries
     * @param list<Operand|null>                                                          $userOperands
     */
    private static function packMagicUserArgs(Context $context, array $userEntries, array $userOperands): ?Variable
    {
        /** @var list<array{0: ?string, 1: Variable}> $parts name or null for positional */
        $parts = [];
        foreach ($userEntries as $i => $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                $operand = $userOperands[$i] ?? null;
                $expanded = self::tryExpandCompileTimeMagicUnpack($context, $operand);
                if (null === $expanded) {
                    return null;
                }
                foreach ($expanded as $part) {
                    $parts[] = $part;
                }
                continue;
            }
            if (\is_array($entry) && isset($entry['named'])) {
                $parts[] = [(string) $entry['named'], $entry['value']];
                continue;
            }
            if ($entry instanceof Variable) {
                $parts[] = [null, $entry];
            }
        }

        return self::packMagicArgumentsArray($context, $parts);
    }

    /**
     * @return list<array{0: ?string, 1: Variable}>|null
     */
    private static function tryExpandCompileTimeMagicUnpack(Context $context, mixed $operand): ?array
    {
        if (!$operand instanceof Operand) {
            return null;
        }
        $block = $context->jitEnclosingBlock;
        if (null === $block) {
            return null;
        }
        $vmArray = CallUnpackCompileTime::tryCompileTimeArrayFromOperand($block, $operand);
        if (null === $vmArray) {
            return null;
        }
        // Open variadic so string keys become named entries (no callee lookup) — #23336.
        $entries = \PHPCompiler\VM\CallUnpack::expandArrayEntries($vmArray, [], 0, null);
        $parts = [];
        foreach ($entries as $entry) {
            if ('p' === $entry[0]) {
                $jitVal = self::vmVariableToJitLiteral($context, $entry[1]);
                if (null === $jitVal) {
                    return null;
                }
                $parts[] = [null, $jitVal];
                continue;
            }
            $jitVal = self::vmVariableToJitLiteral($context, $entry[2]);
            if (null === $jitVal) {
                return null;
            }
            $parts[] = [(string) $entry[1], $jitVal];
        }

        return $parts;
    }

    private static function vmVariableToJitLiteral(Context $context, \PHPCompiler\VM\Variable $vm): ?Variable
    {
        $vm = $vm->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $vm->type) {
            $lit = new Operand\Literal($vm->toInt());
            $lit->type = \PHPTypes\Type::int();

            return Variable::fromLiteral($context, $lit);
        }
        if (\PHPCompiler\VM\Variable::TYPE_FLOAT === $vm->type) {
            $lit = new Operand\Literal($vm->toFloat());
            $lit->type = \PHPTypes\Type::float();

            return Variable::fromLiteral($context, $lit);
        }
        if (\PHPCompiler\VM\Variable::TYPE_STRING === $vm->type) {
            return self::stringVariable($context, $vm->toString());
        }
        if (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $vm->type) {
            $lit = new Operand\Literal($vm->toBool());
            $lit->type = \PHPTypes\Type::bool();

            return Variable::fromLiteral($context, $lit);
        }
        if (\PHPCompiler\VM\Variable::TYPE_NULL === $vm->type) {
            $lit = new Operand\Literal(null);
            $lit->type = \PHPTypes\Type::null();

            return Variable::fromLiteral($context, $lit);
        }

        return null;
    }

    /**
     * @param list<array{0: ?string, 1: Variable}> $parts
     */
    private static function packMagicArgumentsArray(Context $context, array $parts): Variable
    {
        $slot = JitValueBox::alloc($context);
        $array = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        HashTableHelper::initArray($context, $array);
        $hadNamed = false;
        /** @var array<string, true> $namedFilled */
        $namedFilled = [];
        foreach ($parts as [$name, $value]) {
            if (null === $name) {
                if ($hadNamed) {
                    throw new \Error('Cannot use positional argument after named argument');
                }
                HashTableHelper::addElement($context, $array, $value);
                continue;
            }
            if (isset($namedFilled[$name])) {
                throw new \Error("Named parameter \${$name} overwrites previous argument");
            }
            $namedFilled[$name] = true;
            $hadNamed = true;
            $keyVar = self::stringVariable($context, $name);
            HashTableHelper::addElement($context, $array, $value, $keyVar);
        }

        return $array;
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

    private static function stringVariable(Context $context, string $value): Variable
    {
        $lit = new Operand\Literal($value);
        $lit->type = \PHPTypes\Type::string();

        return Variable::fromLiteral($context, $lit);
    }
}
