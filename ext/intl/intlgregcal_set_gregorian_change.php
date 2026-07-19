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

/** intlgregcal_set_gregorian_change() — procedural IntlGregorianCalendar::setGregorianChange (#20906). */
final class intlgregcal_set_gregorian_change extends Internal
{
    public function __construct()
    {
        parent::__construct('intlgregcal_set_gregorian_change');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlgregcal_set_gregorian_change() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlGregorianCalendar, %s given',
                'intlgregcal_set_gregorian_change',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $ts = VmIntlCalendar::coerceFloatArg($frame->calledArgs[1], 'intlgregcal_set_gregorian_change', 1, 'timestamp');
        $ok = VmIntlCalendar::setGregorianChange($receiver->toObject(), $ts);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlgregcal_set_gregorian_change() is not implemented for JIT in this compiler build (issue #20906)');
    }
}
