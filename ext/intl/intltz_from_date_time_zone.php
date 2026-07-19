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
 * intltz_from_date_time_zone() — procedural IntlTimeZone::fromDateTimeZone
 * (php-src timezone_methods.cpp / timezone.stub.php; #20859).
 */
final class intltz_from_date_time_zone extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_from_date_time_zone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_from_date_time_zone() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $zoneVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $zoneVar->type
            || 'datetimezone' !== \strtolower($zoneVar->toObject()->class->name)) {
            throw new \TypeError(\sprintf(
                'intltz_from_date_time_zone(): Argument #1 ($timezone) must be of type DateTimeZone, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($zoneVar)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(
            VmIntlTimeZone::fromDateTimeZone($frame->vmContext, $zoneVar->toObject())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_from_date_time_zone() is not implemented for JIT in this compiler build (issue #20859)');
    }
}