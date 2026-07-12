<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;

/**
 * ReflectionProperty PHP 8.4 hook introspection (#7295, ext/reflection/php_reflection.c).
 */
final class ReflectionPropertyHookSupport
{
    /**
     * @return array{0: Context, 1: ClassEntry, 2: ?ClassProperty, 3: string, 4: string}
     */
    public static function resolveProperty(Frame $frame, Variable $receiverArg): array
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $receiverArg);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        if (ReflectionSupport::isDynamicReflectionProperty($receiver)) {
            return [$ctx, $entry, null, $className, $property];
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta && null === VmReflection::findStaticPropertyKey($entry, $property, $ctx)) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }

        return [$ctx, $entry, $meta, $className, $property];
    }

    public static function isVirtual(ClassEntry $entry, ?ClassProperty $meta, string $property, Context $ctx): bool
    {
        if (null !== $meta) {
            return $meta->propertyHookVirtual;
        }
        $propLc = strtolower($property);
        $hooks = $entry->staticPropertyHooks[$propLc] ?? [];
        if ([] === $hooks) {
            return false;
        }
        $lcClass = strtolower($entry->name);
        $propMeta = $ctx->propertyHookRegistry[$lcClass][$property]
            ?? $ctx->propertyHookRegistry[$lcClass][$propLc]
            ?? null;

        return is_array($propMeta) && !empty($propMeta['virtual']);
    }

    public static function getMangledName(ClassEntry $entry, ?ClassProperty $meta, string $property, Context $ctx): string
    {
        if (null !== $meta) {
            return VmReflection::manglePropertyKey($meta, $ctx);
        }
        if (null === VmReflection::findStaticPropertyKey($entry, $property, $ctx)) {
            return $property;
        }
        $propLc = strtolower($property);
        $visibility = $entry->staticPropertyVisibility[$propLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $declaringLc = $entry->staticPropertyDeclaringClassLc[$propLc] ?? strtolower($entry->name);
        $stub = new ClassProperty($property, null, new Variable());
        $stub->visibility = $visibility;
        $stub->declaringClassLc = $declaringLc;

        return VmReflection::manglePropertyKey($stub, $ctx);
    }

    public static function hasHooks(
        ClassEntry $entry,
        ?ClassProperty $meta,
        string $property,
        Context $ctx
    ): bool {
        if (null !== $meta) {
            return null !== $meta->getHookMethodLc
                || null !== $meta->setHookMethodLc
                || $meta->propertyHookVirtual;
        }
        $hooks = $entry->staticPropertyHooks[strtolower($property)] ?? [];

        return [] !== $hooks;
    }

    public static function hasHook(
        ClassEntry $entry,
        ?ClassProperty $meta,
        string $property,
        string $hookKind
    ): bool {
        if (null !== $meta) {
            return match ($hookKind) {
                'get' => null !== $meta->getHookMethodLc,
                'set' => null !== $meta->setHookMethodLc,
                default => false,
            };
        }
        $hooks = $entry->staticPropertyHooks[strtolower($property)] ?? [];

        return isset($hooks[$hookKind]);
    }

    /**
     * @return array<string, Variable> hook kind (get/set) => Closure
     */
    public static function getHooks(ClassEntry $entry, ?ClassProperty $meta, string $property, Context $ctx): array
    {
        $result = [];
        foreach (['get', 'set'] as $hookKind) {
            $methodLc = self::hookMethodLc($entry, $meta, $property, $hookKind);
            if (null !== $methodLc) {
                $result[$hookKind] = self::hookClosure($ctx, $entry, $methodLc);
            }
        }

        return $result;
    }

    /**
     * php-src ReflectionProperty::getHook — ReflectionMethod for $prop::get|$prop::set (#4806).
     */
    public static function hookReflectionMethod(
        Context $ctx,
        ClassEntry $entry,
        ?ClassProperty $meta,
        string $property,
        string $hookKind
    ): ?Variable {
        $methodLc = self::hookMethodLc($entry, $meta, $property, $hookKind);
        if (null === $methodLc || !isset($entry->methods[$methodLc])) {
            return null;
        }
        $rmClass = $ctx->classes[ReflectionSupport::REFLECTION_METHOD] ?? null;
        if (null === $rmClass) {
            throw new \LogicException('ReflectionMethod is not registered in this compiler build');
        }
        $rm = new ObjectEntry($rmClass);
        $rm->constructed = true;
        $rm->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);
        $rm->getProperty(ReflectionSupport::PROP_METHOD_NAME)->string('$'.$property.'::'.$hookKind);
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object($rm);

        return $out;
    }

    private static function hookMethodLc(
        ClassEntry $entry,
        ?ClassProperty $meta,
        string $property,
        string $hookKind
    ): ?string {
        if (null !== $meta) {
            return match ($hookKind) {
                'get' => $meta->getHookMethodLc,
                'set' => $meta->setHookMethodLc,
                default => null,
            };
        }
        $hooks = $entry->staticPropertyHooks[strtolower($property)] ?? [];

        return $hooks[$hookKind] ?? null;
    }

    public static function parsePropertyHookTypeArg(Variable $arg, string $function): string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type && EnumCaseSupport::isEnumCase($arg->toObject())) {
            $enum = $arg->toObject()->class;
            if ('propertyhooktype' !== strtolower($enum->name)) {
                throw new \TypeError(
                    $function.'(): Argument #1 ($type) must be of type PropertyHookType, '
                    .$enum->name.' given'
                );
            }
            $caseName = strtolower($arg->toObject()->enumCaseName ?? '');

            return match ($caseName) {
                'get' => 'get',
                'set' => 'set',
                default => throw new \ValueError($function.'(): Invalid PropertyHookType enum case'),
            };
        }
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            $case = $arg->toEnumCase();
            if ('propertyhooktype' !== strtolower($case->enumClass->name)) {
                throw new \TypeError(
                    $function.'(): Argument #1 ($type) must be of type PropertyHookType, '
                    .$case->enumClass->name.' given'
                );
            }

            return match (strtolower($case->caseName)) {
                'get' => 'get',
                'set' => 'set',
                default => throw new \ValueError($function.'(): Invalid PropertyHookType enum case'),
            };
        }
        throw new \TypeError(
            $function.'(): Argument #1 ($type) must be of type PropertyHookType, '
            .EnumCaseSupport::typeNameForVariable($arg).' given'
        );
    }

    private static function hookClosure(Context $ctx, ClassEntry $entry, string $methodLc): Variable
    {
        if (!isset($entry->methods[$methodLc])) {
            throw new \LogicException('Property hook method missing from class entry');
        }
        $func = $entry->methods[$methodLc];
        $state = ClosureState::fromWrappedFunc($func);
        $state->boundScopeClass = $entry->name;
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ClosureSupport::wrapState($ctx, $state));

        return $out;
    }
}
