<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;

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
     * @return array<int, Variable> PropertyHookType backing value => Closure
     */
    public static function getHooks(ClassEntry $entry, ?ClassProperty $meta, string $property, Context $ctx): array
    {
        $result = [];
        if (null !== $meta) {
            if (null !== $meta->getHookMethodLc) {
                $result[0] = self::hookClosure($ctx, $entry, $meta->getHookMethodLc);
            }
            if (null !== $meta->setHookMethodLc) {
                $result[1] = self::hookClosure($ctx, $entry, $meta->setHookMethodLc);
            }

            return $result;
        }
        $hooks = $entry->staticPropertyHooks[strtolower($property)] ?? [];
        if (isset($hooks['get'])) {
            $result[0] = self::hookClosure($ctx, $entry, $hooks['get']);
        }
        if (isset($hooks['set'])) {
            $result[1] = self::hookClosure($ctx, $entry, $hooks['set']);
        }

        return $result;
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
