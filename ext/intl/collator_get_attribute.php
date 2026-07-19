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
 * collator_get_attribute() — procedural Collator::getAttribute
 * (php-src collator_attr.c / collator.stub.php; #20801).
 */
final class collator_get_attribute extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_get_attribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_get_attribute() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'collator_get_attribute(): Argument #1 ($object) must be of type Collator, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $attribute = VmCollator::coerceIntArg($frame->calledArgs[1], 'collator_get_attribute', 1, 'attribute');
        $result = VmCollator::getAttribute($receiver->toObject(), $attribute);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int((int) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('collator_get_attribute() is not implemented for JIT in this compiler build (issue #20801)');
    }
}
