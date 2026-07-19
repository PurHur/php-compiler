<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * datefmt_get_error_message() — procedural IntlDateFormatter::getErrorMessage
 * (php-src dateformat_attr.c / dateformat.stub.php; #20803).
 */
final class datefmt_get_error_message extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_get_error_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_get_error_message() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'datefmt_get_error_message(): Argument #1 ($formatter) must be of type IntlDateFormatter, %s given',
                ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmIntlDateFormatter::getErrorMessage($receiver->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('datefmt_get_error_message() is not implemented for JIT in this compiler build (issue #20803)');
    }
}
