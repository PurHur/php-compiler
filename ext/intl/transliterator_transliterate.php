<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * transliterator_transliterate() — php-src transliterator_methods.c (#6139, #22161).
 *
 * Procedural form accepts Transliterator|string (Z_PARAM_OBJ_OF_CLASS_OR_STR).
 */
final class transliterator_transliterate extends Internal
{
    public function __construct()
    {
        parent::__construct('transliterator_transliterate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_transliterate() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        $trArg = $frame->calledArgs[0]->resolveIndirect();
        $ownedTemp = Variable::TYPE_OBJECT !== $trArg->type
            || !VmTransliterator::isTransliteratorObject($trArg->toObject());
        $tr = VmTransliterator::resolveProceduralTransliteratorArg($frame, $trArg);
        if (null === $tr) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $subject = VmTransliterator::coerceSubjectArg($frame->calledArgs[1], 'transliterator_transliterate', 1);
        $start = 0;
        $end = -1;
        if ($argc >= 3) {
            $start = (int) $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        if ($argc >= 4) {
            $end = (int) $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $result = VmTransliterator::transliterate($tr, $subject, $start, $end);
        if ($ownedTemp) {
            VmTransliterator::release($tr);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_transliterate() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        // AOT/JIT compile-time fold for string ID + string subject literals (#22161).
        $folded = self::tryStringIdFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \Error('transliterator_transliterate() is not implemented for JIT in this compiler build (issue #6139)');
    }

    /**
     * @param JITVariable[] $args
     */
    private static function tryStringIdFold(Context $context, array $args): ?Value
    {
        if (JITVariable::TYPE_STRING !== $args[0]->type
            || null === ($args[0]->compileTimeString ?? null)
            || JITVariable::TYPE_STRING !== $args[1]->type
            || null === ($args[1]->compileTimeString ?? null)
        ) {
            return null;
        }
        $start = 0;
        $end = -1;
        if (isset($args[2])) {
            $startCt = self::compileTimeInt($args, 2);
            if (null === $startCt) {
                return null;
            }
            $start = $startCt;
        }
        if (isset($args[3])) {
            $endCt = self::compileTimeInt($args, 3);
            if (null === $endCt) {
                return null;
            }
            $end = $endCt;
        }
        $result = VmTransliterator::transliterateId(
            $args[0]->compileTimeString,
            $args[1]->compileTimeString,
            $start,
            $end
        );
        if (false === $result) {
            return $context->constantFromBool(false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeInt(array $args, int $index): ?int
    {
        if (!isset($args[$index])) {
            return 0;
        }
        $arg = $args[$index];
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $const = $arg->value;
        if ($const instanceof Value && $const->isConstant()) {
            return (int) $const->constInt();
        }

        return null;
    }
}
