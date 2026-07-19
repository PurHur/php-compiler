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
 * intltz_to_date_time_zone() — procedural IntlTimeZone::toDateTimeZone
 * (php-src timezone_methods.cpp / timezone.stub.php; #20859).
 */
final class intltz_to_date_time_zone extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_to_date_time_zone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_to_date_time_zone() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_to_date_time_zone(): Argument #1 ($timezone) must be of type IntlTimeZone, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $object = $receiver->toObject();
        $zone = VmIntlTimeZone::toDateTimeZone($frame->vmContext, $object);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $zone) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($zone);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_to_date_time_zone() is not implemented for JIT in this compiler build (issue #20859)');
    }
}