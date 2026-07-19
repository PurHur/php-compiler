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
 * collator_get_error_message() — procedural Collator::getErrorMessage
 * (php-src collator_error.c / collator.stub.php; #20801).
 */
final class collator_get_error_message extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_get_error_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_get_error_message() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'collator_get_error_message(): Argument #1 ($object) must be of type Collator, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmCollator::getErrorMessage($receiver->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('collator_get_error_message() is not implemented for JIT in this compiler build (issue #20801)');
    }
}
