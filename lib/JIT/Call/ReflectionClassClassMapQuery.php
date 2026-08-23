<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassClassMapRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::{getInterfaces,getTraits} — JIT/AOT (#34121).
 *
 * Thin AOT previously had no proxies; ExternalMethod → NULL. Materialize
 * FQCN⇒ReflectionClass maps via {@see ReflectionClassClassMapRuntime}.
 * Peer getInterfaceNames/getTraitNames (#34110).
 *
 * php-src: zim_ReflectionClass_getInterfaces / getTraits
 */
final class ReflectionClassClassMapQuery implements Call
{
    /** @var array<string, string> */
    private const METHOD = [
        'interfaces' => 'getInterfaces',
        'traits' => 'getTraits',
    ];

    private static int $blockSeq = 0;

    private string $kindLc;

    public function __construct(string $kind)
    {
        $kindLc = strtolower($kind);
        if (!isset(self::METHOD[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionClass class-map query: '.$kind);
        }
        $this->kindLc = $kindLc;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $method = self::METHOD[$this->kindLc];
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::'.$method,
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_'.$this->kindLc.'_map_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );

        return $this->dispatchByClassId($context, $classIdVal);
    }

    private function dispatchByClassId(Context $context, Value $classId): Value
    {
        $object = $context->type->object;
        /** @var list<int> $ids */
        $ids = [];
        foreach ($object->allClassNamesById() as $id => $name) {
            if (!$object->hasUserDeclaredClass($name)) {
                continue;
            }
            $ids[] = (int) $id;
        }

        if ([] === $ids) {
            return ReflectionClassClassMapRuntime::emitEmpty($context);
        }

        $tag = 'cm'.$this->kindLc.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'refl_cm_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'refl_cm_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );

        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_cm_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'refl_cm_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = ReflectionClassClassMapRuntime::emitForClassId(
                $context,
                $id,
                $this->kindLc
            );
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $empty = ReflectionClassClassMapRuntime::emitEmpty($context);
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $empty),
            $resultSlot
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
