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

/** datefmt_get_calendar_object() — procedural IntlDateFormatter::getCalendarObject (#20860). */
final class datefmt_get_calendar_object extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_get_calendar_object');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_get_calendar_object() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'datefmt_get_calendar_object(): Argument #1 ($formatter) must be of type IntlDateFormatter, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $result = VmIntlDateFormatter::getCalendarObject($receiver->toObject(), $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('datefmt_get_calendar_object() is not implemented for JIT in this compiler build (issue #20860)');
    }
}
