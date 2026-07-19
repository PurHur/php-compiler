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
 * intltz_get_error_message() — procedural IntlTimeZone::getErrorMessage
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_get_error_message extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_error_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_error_message() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_get_error_message(): Argument #1 ($timezone) must be of type IntlTimeZone, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $msg = VmIntlTimeZone::getErrorMessage($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $msg) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($msg);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_error_message() is not implemented for JIT in this compiler build (issue #20925)');
    }
}