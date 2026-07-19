<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * intltz_get_gmt() — procedural IntlTimeZone::getGMT
 * (php-src timezone_methods.cpp / timezone.stub.php; #20859).
 */
final class intltz_get_gmt extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_gmt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_gmt() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createFromId($frame->vmContext, 'GMT'));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_gmt() is not implemented for JIT in this compiler build (issue #20859)');
    }
}