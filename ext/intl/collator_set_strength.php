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
 * collator_set_strength() — procedural Collator::setStrength
 * (php-src collator_attr.c / collator.stub.php; #20801).
 */
final class collator_set_strength extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_set_strength');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_set_strength() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'collator_set_strength(): Argument #1 ($object) must be of type Collator, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $strength = VmCollator::coerceIntArg($frame->calledArgs[1], 'collator_set_strength', 1, 'strength');
        $ok = VmCollator::setStrength($receiver->toObject(), $strength);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('collator_set_strength() is not implemented for JIT in this compiler build (issue #20801)');
    }
}
