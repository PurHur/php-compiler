<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * datefmt_localtime() — procedural IntlDateFormatter::localtime
 * (php-src dateformat_format.c / dateformat.stub.php; #20803).
 */
final class datefmt_localtime extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_localtime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_localtime() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'datefmt_localtime(): Argument #1 ($formatter) must be of type IntlDateFormatter, %s given',
                ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'datefmt_localtime', 2, 'string');
        $offset = null;
        $hasOffset = $argc >= 3;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $offsetVar->type) {
                $offset = VmIntlDateFormatter::coerceIntArg($offsetVar, 'datefmt_localtime', 3, 'offset');
            }
        }
        $result = VmIntlDateFormatter::localtime($receiver->toObject(), $text, $offset);
        if ($hasOffset && null !== $offset) {
            $frame->calledArgs[2]->byRefTarget()->int($offset);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        /** @var HashTable $result */
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('datefmt_localtime() is not implemented for JIT in this compiler build (issue #20803)');
    }
}
