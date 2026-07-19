<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intltz_get_display_name() — procedural IntlTimeZone::getDisplayName
 * (php-src timezone_methods.cpp / timezone.stub.php; #20859).
 */
final class intltz_get_display_name extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_display_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_display_name() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_get_display_name(): Argument #1 ($timezone) must be of type IntlTimeZone, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $object = $receiver->toObject();
        $dst = false;
        if ($argc >= 2) {
            $dst = LocaleLookup::coerceBool(
                $frame->calledArgs[1],
                'intltz_get_display_name',
                1,
                'dst'
            );
        }
        $style = VmIntlTimeZone::DISPLAY_LONG;
        if ($argc >= 3) {
            $style = VmIntlDateFormatter::coerceIntArg(
                $frame->calledArgs[2],
                'intltz_get_display_name',
                2,
                'style'
            );
        }
        $locale = null;
        if ($argc >= 4) {
            $localeVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[3],
                    'intltz_get_display_name',
                    3,
                    'locale'
                );
            }
        }
        $name = VmIntlTimeZone::getDisplayName($object, $dst, $style, $locale);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_display_name() is not implemented for JIT in this compiler build (issue #20859)');
    }
}