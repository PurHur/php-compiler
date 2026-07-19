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
 * intltz_get_offset() — procedural IntlTimeZone::getOffset
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_get_offset extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_offset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (5 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_offset() expects exactly 5 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_get_offset(): Argument #1 ($timezone) must be of type IntlTimeZone, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $object = $receiver->toObject();
        $tsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_FLOAT === $tsVar->type) {
            $timestamp = $tsVar->toFloat();
        } elseif (Variable::TYPE_INTEGER === $tsVar->type) {
            $timestamp = (float) $tsVar->toInt();
        } else {
            throw new \TypeError(\sprintf(
                'intltz_get_offset(): Argument #2 ($date) must be of type float, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($tsVar)
            ));
        }
        $local = LocaleLookup::coerceBool(
            $frame->calledArgs[2],
            'intltz_get_offset',
            2,
            'local'
        );
        $raw = 0;
        $dst = 0;
        $ok = VmIntlTimeZone::getOffset($object, $timestamp, $local, $raw, $dst);
        $frame->calledArgs[3]->resolveIndirect()->int($raw);
        $frame->calledArgs[4]->resolveIndirect()->int($dst);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_offset() is not implemented for JIT in this compiler build (issue #20925)');
    }
}