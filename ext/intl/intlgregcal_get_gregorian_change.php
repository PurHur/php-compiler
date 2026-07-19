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

/** intlgregcal_get_gregorian_change() — procedural IntlGregorianCalendar::getGregorianChange (#20906). */
final class intlgregcal_get_gregorian_change extends Internal
{
    public function __construct()
    {
        parent::__construct('intlgregcal_get_gregorian_change');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlgregcal_get_gregorian_change() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlGregorianCalendar, %s given',
                'intlgregcal_get_gregorian_change',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmIntlCalendar::getGregorianChange($receiver->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlgregcal_get_gregorian_change() is not implemented for JIT in this compiler build (issue #20906)');
    }
}
