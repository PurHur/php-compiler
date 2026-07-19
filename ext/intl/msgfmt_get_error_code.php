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
 * msgfmt_get_error_code() — procedural MessageFormatter::getErrorCode
 * (php-src msgfmt_error.c / msgfmt.stub.php; #20802).
 */
final class msgfmt_get_error_code extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_get_error_code');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_get_error_code() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $formatter = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $formatter->type
            || !VmMessageFormatter::isFormatterObject($formatter->toObject())) {
            throw new \TypeError(\sprintf(
                'msgfmt_get_error_code(): Argument #1 ($formatter) must be of type MessageFormatter, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($formatter)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmMessageFormatter::getErrorCode($formatter->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgfmt_get_error_code() is not implemented for JIT in this compiler build (issue #20802)');
    }
}
