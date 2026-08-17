<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionMethod::invoke($object, ...$args) — AOT (#30910, #7117).
 *
 * Dispatches by strcmp of ReflectionMethod::$class / $name against compile-unit
 * instance method proxies (including private — Zend 8.1+ ignores accessible).
 */
final class ReflectionMethodInvoke implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'ReflectionMethod::invoke() expects at least 1 argument, '.(\count($args) - 1).' given'
            );
        }
        $rm = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classCstr] = ReflectionSetup::stringPropertyAsCstr($context, $rm, 'ReflectionMethod', 'class');
        [$methodCstr] = ReflectionSetup::stringPropertyAsCstr($context, $rm, 'ReflectionMethod', 'name');
        $objectVar = $args[1];
        $invokeArgs = \array_slice($args, 2);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        \PHPCompiler\JIT\LibcExtern::ensureStrcmpDecl($context);
        \PHPCompiler\JIT\Builtin\StringCaseCompare::ensureStrcasecmpLinked($context);
        $strcmp = $context->lookupFunction('strcmp');
        // __compiler_strcasecmp after LibcExtern always-on drop (#31787).
        $strcasecmp = $context->lookupFunction(\PHPCompiler\JIT\Builtin\StringCaseCompare::ABI_STRCASECMP);

        $tag = 'rmi'.(string) (++self::$seq);
        $merge = BasicBlockHelper::append($context, 'refl_method_invoke_merge_'.$tag);
        $miss = BasicBlockHelper::append($context, 'refl_method_invoke_miss_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $null = $context->getTypeFromString('__value__*')->constNull();

        /** @var list<array{class: string, method: string, proxy: string}> $targets */
        $targets = [];
        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $classId => $className) {
            if (!$object->hasUserDeclaredClass($className)) {
                continue;
            }
            $classLc = strtolower(ltrim($className, '\\'));
            foreach ($object->declaredMethodNames((int) $classId) as $methodLc) {
                $vis = $object->methodVisibility((int) $classId, $methodLc);
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
                $proxyName = $classLc.'::'.$methodLc;
                if (!$context->functionIsRegistered($proxyName)) {
                    // Walk parents for inherited method body.
                    $parent = $object->parentClassLc($classLc);
                    $resolved = $proxyName;
                    while (null !== $parent) {
                        $try = $parent.'::'.$methodLc;
                        if ($context->functionIsRegistered($try)) {
                            $resolved = $try;
                            break;
                        }
                        $parent = $object->parentClassLc($parent);
                    }
                    if (!$context->functionIsRegistered($resolved)) {
                        continue;
                    }
                    $proxyName = $resolved;
                }
                $proxy = $context->resolveFunctionProxy($proxyName);
                // Only Native arms with matching LLVM arity — other Call shapes coerce
                // invoke trailing args into the wrong param type (#30910).
                if (!($proxy instanceof Native)) {
                    continue;
                }
                $need = 1 + \count($invokeArgs); // $this + trailing
                $have = \count($proxy->argTypes);
                if (null !== $proxy->variadicArgIndex) {
                    if ($need < $proxy->variadicArgIndex) {
                        continue;
                    }
                } elseif ($have !== $need) {
                    continue;
                }
                $targets[] = [
                    'class' => ltrim($className, '\\'),
                    'method' => $methodLc,
                    'proxy' => $proxyName,
                ];
            }
        }

        if ([] === $targets) {
            $context->builder->store($null, $resultSlot);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);

            return $context->builder->load($resultSlot);
        }

        $n = \count($targets);
        $checks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_method_invoke_chk_'.$tag.'_'.$i);
        }
        foreach ($targets as $i => $spec) {
            $context->builder->positionAtEnd($checks[$i]);
            $classLit = $context->builder->pointerCast(
                $context->constantFromString($spec['class']),
                $i8p
            );
            $methodLit = $context->builder->pointerCast(
                $context->constantFromString($spec['method']),
                $i8p
            );
            // Class names are case-insensitive; method names are case-insensitive for user methods.
            $classCmp = $context->builder->call($strcasecmp, $classCstr, $classLit);
            $methodCmp = $context->builder->call($strcasecmp, $methodCstr, $methodLit);
            $classOk = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));
            $methodOk = $context->builder->icmp(Builder::INT_EQ, $methodCmp, $i32->constInt(0, false));
            $both = $context->builder->and($classOk, $methodOk);
            $onMatch = BasicBlockHelper::append($context, 'refl_method_invoke_hit_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checks[$i + 1] : $miss;
            $context->builder->branchIf($both, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $proxy = $context->resolveFunctionProxy($spec['proxy']);
            $raw = $proxy->call($context, $objectVar, ...$invokeArgs);
            $have = $context->getStringFromType($raw->typeOf());
            if ('__value__*' === $have) {
                $context->builder->store($raw, $resultSlot);
            } elseif ('void' === $have || 'void*' === $have) {
                $context->builder->store($null, $resultSlot);
            } else {
                $boxed = JitValueBox::alloc($context);
                $ptr = JitValueBox::pointer($context, $boxed);
                if ('int64' === $have || 'int32' === $have || 'int1' === $have) {
                    $long = 'int64' === $have
                        ? $raw
                        : $context->builder->sExt($raw, $context->getTypeFromString('int64'));
                    $context->builder->call($context->lookupFunction('__value__writeLong'), $ptr, $long);
                } elseif ('double' === $have) {
                    $context->builder->call($context->lookupFunction('__value__writeDouble'), $ptr, $raw);
                } elseif ('__string__*' === $have) {
                    $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $raw);
                } elseif ('__object__*' === $have) {
                    $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $raw);
                } else {
                    $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                }
                $context->builder->store($ptr, $resultSlot);
            }
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($miss);
        $context->builder->store($null, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
