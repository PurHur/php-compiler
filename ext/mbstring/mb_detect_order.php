<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_detect_order() — encoding detection order (php-src ext/mbstring/mbstring.c; #13100, #29920, #35278).
 *
 * JIT/AOT: omitted/null getter fold; compile-time string or array setter updates
 * {@see MbstringAotFoldState} (peer {@see JitMbDetectEncoding} list fold).
 */
final class mb_detect_order extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_detect_order');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_detect_order() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src Z_PARAM_ARRAY_OR_STRING_OR_NULL — omitted/null selects getter
        // (mbstring.stub.php array|string|null $encoding = null); #29920.
        if (0 === $argc
            || Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type
        ) {
            $frame->returnVar->array(
                MbstringState::hashTableFromStringList(MbstringState::detectOrder())
            );

            return;
        }
        $result = MbstringState::detectOrder($frame->calledArgs[0]);
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_detect_order() expects at most 1 argument, %d given',
                $argc
            ));
        }
        // Compile-time omitted/null getter fold (php-src Z_PARAM_ARRAY_OR_STRING_OR_NULL).
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            $order = MbstringAotFoldState::detectOrder($context) ?? MbstringState::detectOrder();
            $ht = MbstringState::hashTableFromStringList($order);
            $cacheKey = 'mb_detect_order_'.implode(',', $order);
            $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
            $slot = JitValueBox::alloc($context);
            JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

            return $slot;
        }

        $parsed = self::compileTimeOrder($context, $args[0]);
        if (null === $parsed) {
            throw new \LogicException(
                'mb_detect_order() JIT setter requires a compile-time string or array of string literals in this compiler build'
            );
        }
        MbstringAotFoldState::setDetectOrder($context, $parsed);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    /**
     * Compile-time string CSV or packed native / compileTimeArray encoding list (#35278).
     *
     * @return list<string>|null
     */
    private static function compileTimeOrder(Context $context, JITVariable $arg): ?array
    {
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $encodingLit) {
            return MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $encodingLit);
        }

        $fromNative = self::compileTimeOrderFromNativeArray($context, $arg);
        if (null !== $fromNative) {
            return $fromNative;
        }

        $arr = $arg->compileTimeArray ?? null;
        if (null === $arr) {
            return null;
        }
        $order = [];
        foreach ($arr as $elem) {
            if (\is_string($elem)) {
                $s = $elem;
            } elseif ($elem instanceof JITVariable) {
                $s = JitStringArg::compileTimeLiteral($elem);
                if (null === $s) {
                    return null;
                }
            } else {
                return null;
            }
            $canonical = MbstringEncodingRegistry::resolve($s);
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "%s"',
                    $s
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_order', 0, $order);

        return $order;
    }

    /**
     * Packed native-array encoding list (`['UTF-8','ASCII']`) via dimFetch (#35278).
     *
     * @return list<string>|null
     */
    private static function compileTimeOrderFromNativeArray(Context $context, JITVariable $arg): ?array
    {
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $n = $arg->nextFreeElement;
        if ($n <= 0) {
            return null;
        }
        $order = [];
        for ($i = 0; $i < $n; ++$i) {
            $elem = $arg->dimFetch(JITVariable::fromConstantInt($context, $i));
            $s = JitStringArg::compileTimeLiteral($elem);
            if (null === $s) {
                return null;
            }
            $canonical = MbstringEncodingRegistry::resolve($s);
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "%s"',
                    $s
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_order', 0, $order);

        return $order;
    }
}
