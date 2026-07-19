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
 * intltz_use_daylight_time() — procedural IntlTimeZone::useDaylightTime
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_use_daylight_time extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_use_daylight_time');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_use_daylight_time() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_use_daylight_time(): Argument #1 ($timezone) must be of type IntlTimeZone, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $object = $receiver->toObject();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlTimeZone::useDaylightTime($object));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_use_daylight_time() is not implemented for JIT in this compiler build (issue #20925)');
    }
}