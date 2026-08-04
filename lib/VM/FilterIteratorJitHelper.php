<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT FilterIterator — snapshot inner `__spl_ht`, walk via pos + accept() (#27565).
 *
 * php-src: ext/spl/spl_iterators.c — spl_FilterIterator / dual_it_fetch
 *
 * User subclasses implement accept(); rewind/next skip until accept() is true.
 * Not listed in {@see SplOuterIteratorHt} — foreach must call Iterator methods so
 * accept() runs (and `$this->current()` inside accept resolves).
 */
final class FilterIteratorJitHelper
{
    public const PROP_HT = SplHtPosIteratorJitHelper::PROP_HT;

    public const PROP_POS = SplHtPosIteratorJitHelper::PROP_POS;

    private static int $fetchSeq = 0;

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $inner
    ): Value {
        return SplHtPosIteratorJitHelper::compileConstruct(
            $context,
            $receiver,
            $inner,
            'FilterIterator'
        );
    }

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        SplHtPosIteratorJitHelper::compileRewind(
            $context,
            $receiver,
            'FilterIterator',
            SplHtPosIteratorJitHelper::REWIND_RESET
        );

        return self::compileFetch($context, $receiver);
    }

    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        SplHtPosIteratorJitHelper::compileNext(
            $context,
            $receiver,
            'FilterIterator',
            SplHtPosIteratorJitHelper::NEXT_STOP
        );

        return self::compileFetch($context, $receiver);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        return SplHtPosIteratorJitHelper::compileValid($context, $receiver, 'FilterIterator');
    }

    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        return SplHtPosIteratorJitHelper::compileCurrent($context, $receiver, 'FilterIterator');
    }

    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        return SplHtPosIteratorJitHelper::compileKey($context, $receiver, 'FilterIterator');
    }

    /**
     * dual_it_fetch — advance while valid && !accept().
     */
    public static function compileFetch(Context $context, JITVariable $receiver): Value
    {
        $tag = (string) (++self::$fetchSeq);
        $head = BasicBlockHelper::append($context, 'filter_it_fetch_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'filter_it_fetch_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'filter_it_fetch_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $validSlot = self::compileValid($context, $receiver);
        $validPtr = JitValueBox::valuePtrFromVariable(
            $context,
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $validSlot)
        );
        $validByte = JitValueBox::readBoolByte($context, $validPtr);
        $i8 = $context->getTypeFromString('int8');
        $isValid = $context->builder->icmp(
            Builder::INT_NE,
            $validByte,
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($isValid, $body, $done);

        $context->builder->positionAtEnd($body);
        $accepted = self::emitAcceptCall($context, $receiver);
        $acceptPtr = JitValueBox::normalizeValuePtr($context, $accepted);
        $acceptByte = JitValueBox::readBoolByte($context, $acceptPtr);
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $acceptByte,
            $i8->constInt(0, false)
        );
        $advance = BasicBlockHelper::append($context, 'filter_it_fetch_adv_'.$tag);
        $context->builder->branchIf($ok, $done, $advance);

        $context->builder->positionAtEnd($advance);
        SplHtPosIteratorJitHelper::compileNext(
            $context,
            $receiver,
            'FilterIterator',
            SplHtPosIteratorJitHelper::NEXT_STOP
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return self::voidResult($context);
    }

    private static function emitAcceptCall(Context $context, JITVariable $receiver): Value
    {
        $candidates = self::acceptCandidatesByClassId($context);
        if ([] === $candidates) {
            throw new \LogicException(
                'FilterIterator::accept() has no JIT candidates — subclass accept missing (#27565)'
            );
        }
        $dispatch = new RuntimeIndirectInstanceMethodCall($receiver, 'accept', $candidates);

        return $dispatch->call($context, $receiver);
    }

    /**
     * @return array<int, Call>
     */
    private static function acceptCandidatesByClassId(Context $context): array
    {
        $object = $context->type->object;
        $candidates = [];
        foreach ($context->functionProxies as $proxyName => $proxy) {
            if (!$proxy instanceof Call) {
                continue;
            }
            if (!str_ends_with($proxyName, '::accept')) {
                continue;
            }
            $classLc = substr($proxyName, 0, -\strlen('::accept'));
            if (!$object->hasDeclaredClass($classLc)) {
                continue;
            }
            if (
                'filteriterator' !== $classLc
                && !$object->classIsInstanceOf($classLc, 'FilterIterator')
            ) {
                continue;
            }
            $classId = $object->lookup($classLc);
            $vis = $object->methodVisibility($classId, 'accept');
            if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                continue;
            }
            $candidates[$classId] = $proxy;
        }

        return $candidates;
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
