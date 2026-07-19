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

/** datefmt_set_pattern() — procedural IntlDateFormatter::setPattern (#20837). */
final class datefmt_set_pattern extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_set_pattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_set_pattern() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'datefmt_set_pattern(): Argument #1 ($formatter) must be of type IntlDateFormatter, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $pattern = VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[1], 'datefmt_set_pattern', 1);
        if (null === $pattern) {
            throw new \TypeError(
                'datefmt_set_pattern(): Argument #2 ($pattern) must be of type string, null given'
            );
        }
        $ok = VmIntlDateFormatter::setPattern($receiver->toObject(), $pattern);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('datefmt_set_pattern() is not implemented for JIT in this compiler build (issue #20837)');
    }
}
