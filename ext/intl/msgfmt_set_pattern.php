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
 * msgfmt_set_pattern() — procedural MessageFormatter::setPattern
 * (php-src msgfmt_attr.c / msgfmt.stub.php; #20802).
 */
final class msgfmt_set_pattern extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_set_pattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_set_pattern() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $formatter = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $formatter->type
            || !VmMessageFormatter::isFormatterObject($formatter->toObject())) {
            throw new \TypeError(\sprintf(
                'msgfmt_set_pattern(): Argument #1 ($formatter) must be of type MessageFormatter, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($formatter)
            ));
        }
        $pattern = VmMessageFormatter::coercePatternArgFromFrame($frame, 1, 'msgfmt_set_pattern', 1);
        $ok = VmMessageFormatter::setPattern($formatter->toObject(), $pattern);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgfmt_set_pattern() is not implemented for JIT in this compiler build (issue #20802)');
    }
}
