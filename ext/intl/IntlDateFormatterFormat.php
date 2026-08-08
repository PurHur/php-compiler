<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * IntlDateFormatter::format() — ICU pattern subset (php-src dateformat_format.c; #19549 / #5201).
 */
final class IntlDateFormatterFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Instance method: $this + datetime
        if ($argc < 2 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::format() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \LogicException('IntlDateFormatter::format() called on incompatible object');
        }
        $result = VmIntlDateFormatter::format($receiver->toObject(), $frame->calledArgs[1], $frame);
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
        return JitIntlDateFormatterFormat::invokeMethod($context, ...$args);
    }
}
