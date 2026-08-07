<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Transliterator::create() / transliterator_create() (#28657).
 *
 * Allocates a Transliterator object, stores {@see TransliteratorTransliterateJitHelper::PROP_ID},
 * and stashes the compile-time ID for {@see JitTransliteratorTransliterate} CT fold.
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c — zim_Transliterator_create
 */
final class JitTransliteratorCreate
{
    public static ?string $lastCompileTimeId = null;

    public static function takeLastCompileTimeId(): ?string
    {
        $id = self::$lastCompileTimeId;
        self::$lastCompileTimeId = null;

        return $id;
    }

    /**
     * @param list<JITVariable> $args static create($id, $direction = FORWARD) — no $this
     */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::create() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }

        $idStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[0],
            'Transliterator::create',
            0,
            'id'
        );
        $idLit = JitStringArg::compileTimeLiteral($args[0]);
        self::$lastCompileTimeId = $idLit;

        $objectType = $context->type->object;
        $classId = $objectType->lookup('Transliterator');
        $obj = $objectType->allocate($classId);
        $objectType->defineProperty(
            $classId,
            TransliteratorTransliterateJitHelper::PROP_ID,
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor(
                $obj,
                'Transliterator',
                TransliteratorTransliterateJitHelper::PROP_ID
            ),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $idStr),
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }
}
