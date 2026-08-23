<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InstantiableClassJitGuard;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionClass::newInstance(...$args) — JIT/AOT (#34083, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod aborted (rc=134). Allocate via
 * {@see \PHPCompiler\JIT\Builtin\Type\Object_::allocateForRuntimeClassId}, then dispatch
 * `__construct` through {@see RuntimeIndirectInstanceMethodCall} (peer #34078 /
 * {@see ReflectionAttributeNewInstance}).
 *
 * php-src: zim_ReflectionClass_newInstance
 */
final class ReflectionClassNewInstance implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_newInstance — variadic ctor args; $args[0] is $this
        if ([] === $args) {
            throw new \LogicException('ReflectionClass::newInstance() requires an object receiver');
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );
        $obj = $context->type->object->allocateForRuntimeClassId($classIdVal);

        $thisVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
        $thisVar->addref();

        $userArgs = \array_slice($args, 1);
        $candidates = self::buildConstructCandidates($context, \count($userArgs) > 0);
        if ([] !== $candidates) {
            $dispatch = new RuntimeIndirectInstanceMethodCall(
                $thisVar,
                '__construct',
                $candidates
            );
            $dispatch->call($context, $thisVar, ...$userArgs);
        }

        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }

    /**
     * Compile-time class_id → __construct proxy map (same shape as TYPE_NEW runtime new).
     *
     * @return array<int, Call>
     */
    private static function buildConstructCandidates(Context $context, bool $hasCtorArgs): array
    {
        $object = $context->type->object;
        $candidates = [];
        foreach ($object->allClassNamesById() as $classId => $className) {
            if (null !== InstantiableClassJitGuard::userInstantiationErrorMessage($object, (int) $classId)) {
                continue;
            }
            $classLc = strtolower(ltrim($className, '\\'));
            $proxy = self::resolveConstructProxy($context, $classLc);
            if (null !== $proxy) {
                $candidates[(int) $classId] = $proxy;
                continue;
            }
            if ($hasCtorArgs) {
                $candidates[(int) $classId] = new ReflectionClassNewInstanceNoCtorArgsError($className);
            } else {
                $candidates[(int) $classId] = new NoOpConstruct();
            }
        }

        return $candidates;
    }

    private static function resolveConstructProxy(Context $context, string $classLc): ?Call
    {
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338).
        if ('simplemxml_element' === $current) {
            $current = 'simplexmlelement';
        }
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxyName = $current.'::__construct';
            if ($context->functionIsRegistered($proxyName)) {
                $proxy = $context->resolveFunctionProxy($proxyName);
                if (self::isSafeConstructProxy($proxy)) {
                    return $proxy;
                }
            }
            if ($context->type->object->hasDeclaredClass($current)) {
                $classId = $context->type->object->lookup($current);
                $traitLc = $context->type->object->traitMethodSource($classId, '__construct');
                if (null !== $traitLc) {
                    $traitProxy = $traitLc.'::__construct';
                    if ($context->functionIsRegistered($traitProxy)) {
                        $proxy = $context->resolveFunctionProxy($traitProxy);
                        if (self::isSafeConstructProxy($proxy)) {
                            return $proxy;
                        }
                    }
                }
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    private static function isSafeConstructProxy(Call $proxy): bool
    {
        return $proxy instanceof Native
            || $proxy instanceof ExceptionConstruct
            || $proxy instanceof SensitiveParameterValueConstruct
            || $proxy instanceof Vararg
            || $proxy instanceof CoreFunc\Internal;
    }
}

/**
 * Class has no constructor but ReflectionClass::newInstance received ctor args (#34083).
 *
 * php-src throws ReflectionException; thin AOT raises Error with the same message text
 * (peer AttributeNewInstance no-ctor path / ErrorRaise).
 */
final class ReflectionClassNewInstanceNoCtorArgsError implements Call
{
    public function __construct(private string $className)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            ReflectionSupport::reflectionClassNoCtorArgsMessage($this->className)
        );
        $context->builder->call($context->lookupFunction('abort'));

        return JitValueBox::alloc($context);
    }
}
