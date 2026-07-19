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
 * collator_set_attribute() — procedural Collator::setAttribute
 * (php-src collator_attr.c / collator.stub.php; #20801).
 */
final class collator_set_attribute extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_set_attribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_set_attribute() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'collator_set_attribute(): Argument #1 ($object) must be of type Collator, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $attribute = VmCollator::coerceIntArg($frame->calledArgs[1], 'collator_set_attribute', 1, 'attribute');
        $value = VmCollator::coerceIntArg($frame->calledArgs[2], 'collator_set_attribute', 2, 'value');
        $ok = VmCollator::setAttribute($receiver->toObject(), $attribute, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('collator_set_attribute() is not implemented for JIT in this compiler build (issue #20801)');
    }
}
