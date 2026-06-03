<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\LazyObjectNative;
use PHPCompiler\JIT\Builtin\LazyObjectRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * MCJIT lazy object init on first use (#4940, Zend/zend_lazy_objects.c).
 */
final class LazyObjectHelper
{
    public static function registerInitProxy(Context $context, Call $proxy): int
    {
        $index = \count($context->lazyInitProxies);
        $context->lazyInitProxies[$index] = $proxy;

        return $index;
    }

    public static function emitEnsureInitialized(Context $context, Value $obj): void
    {
        if ([] === $context->lazyInitProxies) {
            return;
        }

        LazyObjectNative::registerDeclarations($context);
        LazyObjectRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $objArg = $context->builder->pointerCast($obj, $i8p);
        $pending = $context->builder->call(
            $context->lookupFunction('phpc_lazy_is_pending'),
            $objArg
        );
        $isPending = $context->builder->icmp(
            Builder::INT_NE,
            $pending,
            $i32->constInt(0, false)
        );

        $entry = $context->builder->getInsertBlock();
        $fn = BasicBlockHelper::parentFunction($context);
        $skip = $fn->appendBasicBlock('lazy_skip_init');
        $init = $fn->appendBasicBlock('lazy_do_init');
        $merge = $fn->appendBasicBlock('lazy_init_merge');

        $context->builder->branchIf($isPending, $init, $skip);
        $context->builder->positionAtEnd($skip);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($init);
        self::emitInitBody($context, $obj, $objArg);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function emitInitBody(Context $context, Value $obj, Value $objArg): void
    {
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );

        $ghost = $context->builder->call(
            $context->lookupFunction('phpc_lazy_is_ghost'),
            $objArg
        );
        $initIndex = $context->builder->call(
            $context->lookupFunction('phpc_lazy_init_index'),
            $objArg
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $i32 = $context->getTypeFromString('int32');
        $done = $fn->appendBasicBlock('lazy_init_done');
        $n = \count($context->lazyInitProxies);
        $checkBlock = $context->builder->getInsertBlock();

        foreach ($context->lazyInitProxies as $idx => $proxy) {
            $matchBlock = $fn->appendBasicBlock('lazy_init_proxy_'.$idx);
            $nextBlock = ($idx < $n - 1)
                ? $fn->appendBasicBlock('lazy_init_check_'.($idx + 1))
                : $fn->appendBasicBlock('lazy_init_unknown');

            $context->builder->positionAtEnd($checkBlock);
            $expected = $context->constantFromInteger($idx, 'int32');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $initIndex, $expected);
            $context->builder->branchIf($isMatch, $matchBlock, $nextBlock);

            $context->builder->positionAtEnd($matchBlock);
            $isGhost = $context->builder->icmp(
                Builder::INT_NE,
                $ghost,
                $i32->constInt(0, false)
            );
            $ghostBlock = $fn->appendBasicBlock('lazy_init_ghost_'.$idx);
            $proxyBlock = $fn->appendBasicBlock('lazy_init_proxy_body_'.$idx);
            $context->builder->branchIf($isGhost, $ghostBlock, $proxyBlock);

            $context->builder->positionAtEnd($ghostBlock);
            $objectType->resetInstancePropertySlots($obj, $classIdVal);
            $objectType->applyLazyGhostPropertyDefaults($obj, $classIdVal);
            $thisVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            $thisVar->addref();
            $proxy->call($context, $thisVar);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($proxyBlock);
            $result = $proxy->call($context);
            $realObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::coerceToValuePtrForStore($context, $result)
            );
            $realClassId = $context->builder->load(
                $context->builder->structGep($realObj, $map['class_id'])
            );
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $realClassId, $classIdVal);
            $classOk = $fn->appendBasicBlock('lazy_proxy_class_ok_'.$idx);
            $classBad = $fn->appendBasicBlock('lazy_proxy_class_bad_'.$idx);
            $context->builder->branchIf($classMatch, $classOk, $classBad);
            $context->builder->positionAtEnd($classBad);
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->positionAtEnd($classOk);
            $objectType->copyInstancePropertiesFrom($obj, $realObj, $classIdVal);
            $context->builder->branch($done);

            $checkBlock = $nextBlock;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->call($context->lookupFunction('phpc_lazy_mark_done'), $objArg);
        $objectType->markObjectConstructed($obj);
    }
}
