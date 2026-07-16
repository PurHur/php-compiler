<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: compile-time Randomizer via Mt19937Instance (#19574).
 *
 * Tracks engines/randomizers constructed with compile-time literal seeds so
 * getBytesFromString() can emit a constant string matching VM/Zend.
 */
final class JitRandomizerUserScript
{
    /** @var \SplObjectStorage<JITVariable, Mt19937Instance>|null */
    private static ?\SplObjectStorage $engines = null;

    /** @var array<string, Mt19937Instance> */
    private static array $enginesByToken = [];

    /** @var \SplObjectStorage<JITVariable, Mt19937Instance>|null */
    private static ?\SplObjectStorage $randomizers = null;

    /** @var array<string, Mt19937Instance> */
    private static array $randomizersByToken = [];

    private static ?Mt19937Instance $lastEngine = null;

    private static ?Mt19937Instance $lastRandomizer = null;

    private static int $tokenSeq = 0;

    public static function tryMt19937Construct(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 1) {
            return null;
        }
        $seed = 0;
        if (isset($args[1])) {
            $lit = $args[1]->compileTimeLong;
            if (null === $lit) {
                $str = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
                if (null === $str || str_starts_with($str, '__phpc_rand_')) {
                    return null;
                }
                $seed = (int) $str;
            } else {
                $seed = (int) $lit;
            }
        }
        $engine = new Mt19937Instance();
        $engine->seed($seed & 0xFFFFFFFF, Mt19937Instance::MT_RAND_MT19937);
        self::storeEngine($args[0], $engine);

        return self::returnThis($context, $args[0]);
    }

    public static function tryRandomizerConstruct(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $engine = self::lookupEngine($args[1]);
        if (null === $engine) {
            return null;
        }
        // Clone so Randomizer owns an independent stream (engine may be reused).
        $owned = clone $engine;
        self::storeRandomizer($args[0], $owned);

        return self::returnThis($context, $args[0]);
    }

    public static function tryGetBytesFromString(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3) {
            return null;
        }
        $engine = self::lookupRandomizer($args[0]);
        if (null === $engine) {
            return null;
        }
        $source = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $source || str_starts_with($source, '__phpc_rand_')) {
            return null;
        }
        $length = $args[2]->compileTimeLong;
        if (null === $length) {
            return null;
        }
        try {
            $bytes = RandomizerGetBytesFromStringAlgo::computeFromMt19937(
                $engine,
                $source,
                (int) $length
            );
        } catch (\Throwable) {
            return null;
        }

        return self::boxConstantString($context, $bytes);
    }

    private static function storeEngine(JITVariable $var, Mt19937Instance $engine): void
    {
        if (null === self::$engines) {
            self::$engines = new \SplObjectStorage();
        }
        self::$engines[$var] = $engine;
        $token = '__phpc_rand_eng_'.(++self::$tokenSeq);
        $var->compileTimeString = $token;
        self::$enginesByToken[$token] = $engine;
        self::$lastEngine = $engine;
    }

    private static function storeRandomizer(JITVariable $var, Mt19937Instance $engine): void
    {
        if (null === self::$randomizers) {
            self::$randomizers = new \SplObjectStorage();
        }
        self::$randomizers[$var] = $engine;
        $token = '__phpc_rand_rz_'.(++self::$tokenSeq);
        $var->compileTimeString = $token;
        self::$randomizersByToken[$token] = $engine;
        self::$lastRandomizer = $engine;
    }

    private static function lookupEngine(JITVariable $var): ?Mt19937Instance
    {
        if (null !== self::$engines && isset(self::$engines[$var])) {
            return self::$engines[$var];
        }
        $token = $var->compileTimeString;
        if (null !== $token && isset(self::$enginesByToken[$token])) {
            return self::$enginesByToken[$token];
        }

        return self::$lastEngine;
    }

    private static function lookupRandomizer(JITVariable $var): ?Mt19937Instance
    {
        if (null !== self::$randomizers && isset(self::$randomizers[$var])) {
            return self::$randomizers[$var];
        }
        $token = $var->compileTimeString;
        if (null !== $token && isset(self::$randomizersByToken[$token])) {
            return self::$randomizersByToken[$token];
        }

        return self::$lastRandomizer;
    }

    private static function returnThis(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            $obj = $context->helper->loadValue($receiver);
            $context->type->object->markObjectConstructed($obj);
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                JitValueBox::pointer($context, $slot),
                $obj
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function boxConstantString(Context $context, string $bytes): Value
    {
        $str = $context->builder->load($context->constantStringFromString($bytes));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return $slot;
    }
}
