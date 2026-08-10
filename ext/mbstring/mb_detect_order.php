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

/** mb_detect_order() — encoding detection order (php-src ext/mbstring/mbstring.c; #13100, #29920). */
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

        $encodingLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $encodingLit) {
            throw new \LogicException(
                'mb_detect_order() JIT setter requires a compile-time string in this compiler build'
            );
        }
        $parsed = MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $encodingLit);
        MbstringAotFoldState::setDetectOrder($context, $parsed);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
