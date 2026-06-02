<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\AttributeNewInstanceHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionAttribute::newInstance() — JIT/AOT (#3206, #4598). */
final class ReflectionAttributeNewInstance implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        if ([] === $args) {
            throw new \LogicException('ReflectionAttribute::newInstance() requires an object receiver');
        }
        $attrObj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $nameVar = $context->type->object->propertyFetch($attrObj, 'ReflectionAttribute', 'name');
        $classId = AttributeNewInstanceHelper::emitResolveClassId($context, $nameVar);

        $tag = 'rani'.(string) (++self::$seq);
        $merge = BasicBlockHelper::append($context, 'attr_newinstance_merge_'.$tag);
        $missing = BasicBlockHelper::append($context, 'attr_newinstance_missing_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $null = $context->getTypeFromString('__value__*')->constNull();
        $i64 = $context->getTypeFromString('int64');

        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $id => $displayName) {
            if (!$context->type->object->hasUserDeclaredClass($displayName)) {
                continue;
            }
            $candidates[(int) $id] = strtolower(ltrim($displayName, '\\'));
        }
        $ids = array_keys($candidates);
        $n = count($ids);
        if (0 === $n) {
            AttributeNewInstanceHelper::emitMissingClassError($context);
            $context->builder->store($null, $resultSlot);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);

            return $context->builder->load($resultSlot);
        }

        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'attr_newinstance_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'attr_newinstance_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $missing;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $obj = $context->type->object->allocate($id);
            $thisVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            $thisVar->addref();
            $argsProp = $context->type->object->propertyFetch($attrObj, 'ReflectionAttribute', 'args');
            $ctorArg = AttributeNewInstanceHelper::readFirstPositionalArg($context, $argsProp);
            $proxyName = $candidates[$id].'::__construct';
            if ($context->functionIsRegistered($proxyName)) {
                $context->resolveFunctionProxy($proxyName)->call($context, $thisVar, $ctorArg);
            }
            ReflectionSetup::markConstructed($context, $obj);
            $context->builder->store(AttributeNewInstanceHelper::boxObject($context, $obj), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($missing);
        AttributeNewInstanceHelper::emitMissingClassError($context);
        $context->builder->store($null, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
