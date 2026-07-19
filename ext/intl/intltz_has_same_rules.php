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
 * intltz_has_same_rules() — procedural IntlTimeZone::hasSameRules
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_has_same_rules extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_has_same_rules');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_has_same_rules() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_has_same_rules(): Argument #1 ($timezone) must be of type IntlTimeZone, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $object = $receiver->toObject();
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type
            || !VmIntlTimeZone::isTimeZoneObject($other->toObject())) {
            throw new \TypeError(\sprintf(
                'intltz_has_same_rules(): Argument #2 ($otherTimeZone) must be of type IntlTimeZone, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlTimeZone::hasSameRules($object, $other->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_has_same_rules() is not implemented for JIT in this compiler build (issue #20925)');
    }
}