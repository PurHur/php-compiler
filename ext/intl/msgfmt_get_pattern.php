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
 * msgfmt_get_pattern() — procedural MessageFormatter::getPattern
 * (php-src msgfmt_attr.c / msgfmt.stub.php; #20802).
 */
final class msgfmt_get_pattern extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_get_pattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_get_pattern() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $formatter = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $formatter->type
            || !VmMessageFormatter::isFormatterObject($formatter->toObject())) {
            throw new \TypeError(\sprintf(
                'msgfmt_get_pattern(): Argument #1 ($formatter) must be of type MessageFormatter, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($formatter)
            ));
        }
        $result = VmMessageFormatter::getPattern($formatter->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgfmt_get_pattern() is not implemented for JIT in this compiler build (issue #20802)');
    }
}
