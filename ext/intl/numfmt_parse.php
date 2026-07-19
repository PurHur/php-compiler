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
 * numfmt_parse() — procedural NumberFormatter::parse
 * (php-src formatter.stub.php / formatter_main.c; #20754, #21139).
 */
final class numfmt_parse extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_parse() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_parse(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'numfmt_parse', 1, 'string');
        $type = VmNumberFormatter::TYPE_DOUBLE;
        if ($argc >= 3) {
            $type = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'numfmt_parse', 2, 'type');
        }
        $hasOffset = $argc >= 4;
        $offset = null;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[3]->resolveIndirect();
            $offset = Variable::TYPE_NULL === $offsetVar->type
                ? 0
                : VmIntlDateFormatter::coerceIntArg($offsetVar, 'numfmt_parse', 3, 'offset');
        }
        if ($hasOffset) {
            $result = VmNumberFormatter::parse($receiver->toObject(), $value, $type, $offset);
            $frame->calledArgs[3]->byRefTarget()->int($offset);
        } else {
            $result = VmNumberFormatter::parse($receiver->toObject(), $value, $type);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);
        } else {
            $frame->returnVar->float((float) $result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_parse() is not implemented for JIT in this compiler build (issue #20754)');
    }
}
