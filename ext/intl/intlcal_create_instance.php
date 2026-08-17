<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intlcal_create_instance() — procedural IntlCalendar::createInstance
 * (php-src calendar_methods.c / calendar.stub.php; #20836, named/Reflection #27944).
 *
 * Arginfo: {@see \PHPCompiler\BuiltinParamNames} + {@see \PHPCompiler\BuiltinInternalArgInfo}
 * (`$timezone = null`, `?string $locale = null`): `?IntlCalendar`.
 */
final class intlcal_create_instance extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_create_instance');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_create_instance() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $timezone = VmDate::defaultTimezoneGet();
        if ($argc >= 1) {
            $timezone = VmIntlTimeZone::resolveTimezoneOperand(
                $frame->calledArgs[0],
                $frame->vmContext,
                'intlcal_create_instance',
                0
            );
        }
        $locale = '';
        if ($argc >= 2) {
            $localeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'intlcal_create_instance',
                    1,
                    'locale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlCalendar::createInstance($frame->vmContext, $timezone, $locale));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_create_instance() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
