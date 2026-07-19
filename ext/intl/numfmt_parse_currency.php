<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_parse_currency() — procedural NumberFormatter::parseCurrency (#20780, #21127). */
final class numfmt_parse_currency extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_parse_currency');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // php-src formatter.stub.php — &$currency, &$offset = null (#21127).
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_parse_currency() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_parse_currency() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_parse_currency(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'numfmt_parse_currency', 1, 'string');
        $currencyOut = null;
        $offset = null;
        $hasOffset = $argc >= 4;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $offsetVar->type) {
                $offset = VmIntlDateFormatter::coerceIntArg($offsetVar, 'numfmt_parse_currency', 3, 'offset');
            }
        }
        $result = VmNumberFormatter::parseCurrency(
            $receiver->toObject(),
            $value,
            $currencyOut,
            $offset,
            $hasOffset
        );
        $currencyVar = $frame->calledArgs[2]->resolveIndirect();
        if (null === $currencyOut) {
            $currencyVar->null();
        } else {
            $currencyVar->string($currencyOut);
        }
        if ($hasOffset && null !== $offset) {
            $frame->calledArgs[3]->byRefTarget()->int($offset);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->float($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_parse_currency() is not implemented for JIT in this compiler build (issue #20780)');
    }
}
