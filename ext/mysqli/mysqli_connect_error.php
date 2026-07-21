<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_connect_error() — php-src ext/mysqli/mysqli_api.c (#3435). */
final class mysqli_connect_error extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_connect_error');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $err = VmMysqli::connectError();
        if ('' === $err && 0 === VmMysqli::connectErrno()) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($err);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_connect_error() is not implemented for JIT (issue #3435)');
    }
}
