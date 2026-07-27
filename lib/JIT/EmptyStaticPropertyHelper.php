<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * empty(Class::$prop) — uninitialized typed statics empty without read; else value truthiness (#23983).
 */
final class EmptyStaticPropertyHelper
{
    public static function compile(
        Context $context,
        Operand $classOp,
        ?Operand $nameOp
    ): Value {
        if (!$nameOp instanceof Literal || !is_string($nameOp->value)) {
            throw new \LogicException('empty() on static property with dynamic name is not supported in JIT');
        }
        $object = $context->type->object;
        assert($object instanceof Object_);
        $classId = $object->resolveClassId($classOp);
        $name = $nameOp->value;
        $entry = $object->staticPropertyGlobalEntry($classId, $name);
        if (null === $entry) {
            return $context->constantFromBool(true);
        }
        $i1 = $context->getTypeFromString('int1');
        if (!empty($entry['typedWithoutDefault']) && null !== ($entry['initGlobal'] ?? null)) {
            $initialized = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($entry['initGlobal']),
                $i1->constInt(1, false)
            );
            $fn = BasicBlockHelper::parentFunction($context);
            $uninitBb = $fn->appendBasicBlock('empty_static_uninit');
            $initBb = $fn->appendBasicBlock('empty_static_init');
            $mergeBb = $fn->appendBasicBlock('empty_static_merge');
            $context->builder->branchIf($initialized, $initBb, $uninitBb);
            $context->builder->positionAtEnd($uninitBb);
            $context->builder->branch($mergeBb);
            $context->builder->positionAtEnd($initBb);
            $fetched = self::loadStaticEntryWithoutUninitGuard($context, $entry);
            $valueEmpty = EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $fetched);
            $context->builder->branch($mergeBb);
            $context->builder->positionAtEnd($mergeBb);
            $phi = $context->builder->phi($context->constantFromBool(true)->typeOf());
            $phi->addIncoming($context->constantFromBool(true), $uninitBb);
            $phi->addIncoming($valueEmpty, $initBb);

            return $phi;
        }
        $fetched = self::loadStaticEntryWithoutUninitGuard($context, $entry);

        return EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $fetched);
    }

    /**
     * @param array{global: Value, type: int, initGlobal?: ?Value, typedWithoutDefault?: bool} $entry
     */
    private static function loadStaticEntryWithoutUninitGuard(Context $context, array $entry): Variable
    {
        $loaded = $context->builder->load($entry['global']);
        if (Variable::TYPE_VALUE === $entry['type']) {
            $var = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $loaded
            );
            $var->staticPropertyGlobal = $entry['global'];
            $var->staticPropertyType = $entry['type'];
            $var->staticPropertyInitGlobal = $entry['initGlobal'] ?? null;

            return $var;
        }

        $var = new Variable(
            $context,
            $entry['type'],
            Variable::KIND_VALUE,
            $loaded
        );
        $var->staticPropertyGlobal = $entry['global'];
        $var->staticPropertyType = $entry['type'];
        $var->staticPropertyInitGlobal = $entry['initGlobal'] ?? null;

        return $var;
    }
}
