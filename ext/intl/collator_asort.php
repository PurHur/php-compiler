<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** collator_asort() — procedural Collator::asort (php-src collator_sort.c; #20838). */
final class collator_asort extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_asort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'collator_asort() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'collator_asort(): Argument #1 ($object) must be of type Collator, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $flags = VmCollator::SORT_REGULAR;
        if ($argc >= 3) {
            $flags = VmCollator::coerceSortFlags($frame->calledArgs[2], 'collator_asort', 2);
        }
        $ok = VmCollator::asort($receiver->toObject(), $frame->calledArgs[1], $flags);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('collator_asort() is not implemented for JIT in this compiler build (issue #20838)');
    }
}
