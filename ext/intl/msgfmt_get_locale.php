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
 * msgfmt_get_locale() — procedural MessageFormatter::getLocale
 * (php-src msgfmt_attr.c / msgfmt.stub.php; #20802).
 */
final class msgfmt_get_locale extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_get_locale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_get_locale() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $formatter = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $formatter->type
            || !VmMessageFormatter::isFormatterObject($formatter->toObject())) {
            throw new \TypeError(\sprintf(
                'msgfmt_get_locale(): Argument #1 ($formatter) must be of type MessageFormatter, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($formatter)
            ));
        }
        $result = VmMessageFormatter::getLocale($formatter->toObject());
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
        throw new \Error('msgfmt_get_locale() is not implemented for JIT in this compiler build (issue #20802)');
    }
}
