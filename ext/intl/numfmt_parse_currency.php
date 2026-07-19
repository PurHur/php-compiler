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
 * numfmt_parse_currency() — procedural NumberFormatter::parseCurrency
 * (php-src formatter.stub.php / formatter_parse.c; #20780, #21127).
 */
final class numfmt_parse_currency extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_parse_currency');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_parse_currency() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_parse_currency(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'numfmt_parse_currency', 1, 'string');
        $hasOffset = $argc >= 4;
        $offset = null;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[3]->resolveIndirect();
            $offset = Variable::TYPE_NULL === $offsetVar->type
                ? 0
                : VmIntlDateFormatter::coerceIntArg($offsetVar, 'numfmt_parse_currency', 3, 'offset');
        }
        $currencyOut = null;
        if ($hasOffset) {
            $result = VmNumberFormatter::parseCurrency($receiver->toObject(), $value, $currencyOut, $offset);
            $frame->calledArgs[3]->byRefTarget()->int($offset);
        } else {
            $result = VmNumberFormatter::parseCurrency($receiver->toObject(), $value, $currencyOut);
        }
        $currencyVar = $frame->calledArgs[2]->resolveIndirect();
        if (null === $currencyOut) {
            $currencyVar->null();
        } else {
            $currencyVar->string($currencyOut);
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
