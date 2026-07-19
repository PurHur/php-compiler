<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** datefmt_set_lenient() — procedural IntlDateFormatter::setLenient (#20860). */
final class datefmt_set_lenient extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_set_lenient');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_set_lenient() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'datefmt_set_lenient(): Argument #1 ($formatter) must be of type IntlDateFormatter, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $lenient = VmIntlDateFormatter::coerceBoolArg($frame->calledArgs[1], 'datefmt_set_lenient', 2, 'lenient');
        VmIntlDateFormatter::setLenient($receiver->toObject(), $lenient);
        if (null !== $frame->returnVar) {
            // php-src procedural returns bool; OOP setLenient is void.
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('datefmt_set_lenient() is not implemented for JIT in this compiler build (issue #20860)');
    }
}
