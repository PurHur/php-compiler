<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stats runtime — Welford variance/covariance on __hashtable__ (#5748).
 *
 * Returns quiet NaN on failure (empty array, sample n=1, size mismatch); callers box as false.
 */
final class StatsJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stats_variance',
        '__compiler_stats_covariance',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stats_variance');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);
        self::implementIfMissing($context, '__compiler_stats_variance', self::emitVariance(...));
        self::implementIfMissing($context, '__compiler_stats_covariance', self::emitCovariance(...));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $double = $context->getTypeFromString('double');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $ft = match ($name) {
            '__compiler_stats_covariance' => $context->context->functionType($double, false, $htPtr, $htPtr, $i1),
            default => $context->context->functionType($double, false, $htPtr, $i1),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $name = 'sqrt';
        if (null === $context->module->getNamedFunction($name)) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($double, false, $double)
            );
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitVariance(Context $context, LlvmFunction $fn): void
    {
        $ht = $fn->getParam(0);
        $sample = $fn->getParam(1);
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $state = self::allocWelfordState($context);
        self::iterateNumericList(
            $context,
            $ht,
            $state,
            static function (Context $ctx, Value $x, array $st): void {
                self::welfordUpdate($ctx, $st, $x);
            }
        );

        $n = $context->builder->load($state['n']);
        self::finishVariance($context, $state, $sample, $n);
    }

    private static function emitCovariance(Context $context, LlvmFunction $fn): void
    {
        $htA = $fn->getParam(0);
        $htB = $fn->getParam(1);
        $sample = $fn->getParam(2);
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $nan = $double->constReal(\NAN);

        $numA = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $htA);
        $numB = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $htB);
        $sizeMismatch = $context->builder->icmp(Builder::INT_NE, $numA, $numB);
        $mismatchBlock = BasicBlockHelper::append($context, 'stats_cov_size_mismatch');
        $workBlock = BasicBlockHelper::append($context, 'stats_cov_work');
        $context->builder->branchIf($sizeMismatch, $mismatchBlock, $workBlock);

        $context->builder->positionAtEnd($mismatchBlock);
        $context->builder->returnValue($nan);

        $context->builder->positionAtEnd($workBlock);
        $nSlot = $context->builder->alloca($i64, 1, 'stats_cov_n');
        $meanASlot = $context->builder->alloca($double, 1, 'stats_cov_mean_a');
        $meanBSlot = $context->builder->alloca($double, 1, 'stats_cov_mean_b');
        $carrySlot = $context->builder->alloca($double, 1, 'stats_cov_carry');
        $context->builder->store($i64->constInt(0, false), $nSlot);
        $context->builder->store($double->constReal(0.0), $meanASlot);
        $context->builder->store($double->constReal(0.0), $meanBSlot);
        $context->builder->store($double->constReal(0.0), $carrySlot);

        $idxSlot = $context->builder->alloca($sizeT, 1, 'stats_cov_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'stats_cov_head');
        $body = BasicBlockHelper::append($context, 'stats_cov_body');
        $accum = BasicBlockHelper::append($context, 'stats_cov_accum');
        $next = BasicBlockHelper::append($context, 'stats_cov_next');
        $doneLoop = BasicBlockHelper::append($context, 'stats_cov_done_loop');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $numA);
        $context->builder->branchIf($atEnd, $doneLoop, $body);

        $context->builder->positionAtEnd($body);
        $valA = self::readNumericEntry($context, self::listEntryAt($context, $htA, $idx));
        $valB = self::readNumericEntry($context, self::listEntryAt($context, $htB, $idx));
        $bothNumeric = $context->builder->and($valA['ok'], $valB['ok']);
        $context->builder->branchIf($bothNumeric, $accum, $next);

        $context->builder->positionAtEnd($accum);
        $n = $context->builder->load($nSlot);
        $nNew = $context->builder->add($n, $i64->constInt(1, false));
        $meanA = $context->builder->load($meanASlot);
        $meanB = $context->builder->load($meanBSlot);
        $x = $valA['value'];
        $y = $valB['value'];
        $deltaA = $context->builder->fsub($x, $meanA);
        $meanANew = $context->builder->fadd(
            $meanA,
            $context->builder->fdiv(
                $deltaA,
                $context->builder->sitofp($nNew, $double)
            )
        );
        $meanBNew = $context->builder->fadd(
            $meanB,
            $context->builder->fdiv(
                $context->builder->fsub($y, $meanB),
                $context->builder->sitofp($nNew, $double)
            )
        );
        $carry = $context->builder->load($carrySlot);
        $carryNew = $context->builder->fadd(
            $carry,
            $context->builder->fmul($deltaA, $context->builder->fsub($y, $meanBNew))
        );
        $context->builder->store($nNew, $nSlot);
        $context->builder->store($meanANew, $meanASlot);
        $context->builder->store($meanBNew, $meanBSlot);
        $context->builder->store($carryNew, $carrySlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneLoop);
        $nFinal = $context->builder->load($nSlot);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $nFinal, $zeroI64);
        $sampleOne = $context->builder->and(
            $sample,
            $context->builder->icmp(Builder::INT_EQ, $nFinal, $oneI64)
        );
        $fail = $context->builder->or($empty, $sampleOne);
        $failBlock = BasicBlockHelper::append($context, 'stats_cov_fail');
        $okBlock = BasicBlockHelper::append($context, 'stats_cov_ok');
        $context->builder->branchIf($fail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nan);

        $context->builder->positionAtEnd($okBlock);
        $divisor = $context->builder->select(
            $sample,
            $context->builder->sitofp($context->builder->sub($nFinal, $oneI64), $double),
            $context->builder->sitofp($nFinal, $double)
        );
        $result = $context->builder->fdiv($context->builder->load($carrySlot), $divisor);
        $context->builder->returnValue($result);
    }

    /** @return array{n: Value, mean: Value, m2: Value} */
    private static function allocWelfordState(Context $context): array
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $nSlot = $context->builder->alloca($i64, 1, 'stats_welford_n');
        $meanSlot = $context->builder->alloca($double, 1, 'stats_welford_mean');
        $m2Slot = $context->builder->alloca($double, 1, 'stats_welford_m2');
        $context->builder->store($i64->constInt(0, false), $nSlot);
        $context->builder->store($double->constReal(0.0), $meanSlot);
        $context->builder->store($double->constReal(0.0), $m2Slot);

        return ['n' => $nSlot, 'mean' => $meanSlot, 'm2' => $m2Slot];
    }

    /**
     * @param array{n: Value, mean: Value, m2: Value} $state
     * @param callable(Context, Value, array): void $onNumeric
     */
    private static function iterateNumericList(
        Context $context,
        Value $ht,
        array $state,
        callable $onNumeric
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $num = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'stats_iter_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'stats_iter_head');
        $body = BasicBlockHelper::append($context, 'stats_iter_body');
        $accum = BasicBlockHelper::append($context, 'stats_iter_accum');
        $next = BasicBlockHelper::append($context, 'stats_iter_next');
        $done = BasicBlockHelper::append($context, 'stats_iter_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $entry = self::listEntryAt($context, $ht, $idx);
        $read = self::readNumericEntry($context, $entry);
        $context->builder->branchIf($read['ok'], $accum, $next);

        $context->builder->positionAtEnd($accum);
        $onNumeric($context, $read['value'], $state);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /** @param array{n: Value, mean: Value, m2: Value} $state */
    private static function welfordUpdate(Context $context, array $state, Value $x): void
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $n = $context->builder->load($state['n']);
        $nNew = $context->builder->add($n, $i64->constInt(1, false));
        $mean = $context->builder->load($state['mean']);
        $delta = $context->builder->fsub($x, $mean);
        $meanNew = $context->builder->fadd(
            $mean,
            $context->builder->fdiv($delta, $context->builder->sitofp($nNew, $double))
        );
        $delta2 = $context->builder->fsub($x, $meanNew);
        $m2 = $context->builder->load($state['m2']);
        $m2New = $context->builder->fadd($m2, $context->builder->fmul($delta, $delta2));
        $context->builder->store($nNew, $state['n']);
        $context->builder->store($meanNew, $state['mean']);
        $context->builder->store($m2New, $state['m2']);
    }

    /** @param array{n: Value, mean: Value, m2: Value} $state */
    private static function finishVariance(Context $context, array $state, Value $sample, Value $n): void
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $nan = $double->constReal(\NAN);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $sampleOne = $context->builder->and(
            $sample,
            $context->builder->icmp(Builder::INT_EQ, $n, $one)
        );
        $fail = $context->builder->or($empty, $sampleOne);
        $failBlock = BasicBlockHelper::append($context, 'stats_var_fail');
        $okBlock = BasicBlockHelper::append($context, 'stats_var_ok');
        $context->builder->branchIf($fail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nan);

        $context->builder->positionAtEnd($okBlock);
        $divisor = $context->builder->select(
            $sample,
            $context->builder->sitofp($context->builder->sub($n, $one), $double),
            $context->builder->sitofp($n, $double)
        );
        $result = $context->builder->fdiv($context->builder->load($state['m2']), $divisor);
        $context->builder->returnValue($result);
    }

    /** @return array{ok: Value, value: Value} */
    private static function readNumericEntry(Context $context, Value $entry): array
    {
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );

        $longVal = $context->builder->sitofp(
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry),
            $double
        );
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);

        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $ok = $context->builder->or($isLong, $context->builder->or($isDouble, $isBool));
        $fromLong = $context->builder->select($isDouble, $doubleVal, $longVal);
        $value = $context->builder->select($isBool, $longVal, $fromLong);

        return ['ok' => $ok, 'value' => $value];
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }
}
