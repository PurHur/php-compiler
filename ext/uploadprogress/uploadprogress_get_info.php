<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uploadprogress;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** uploadprogress_get_info() — PECL uploadprogress (ext/uploadprogress/uploadprogress.c; #6386). */
final class uploadprogress_get_info extends Internal
{
    public function __construct()
    {
        parent::__construct('uploadprogress_get_info');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'uploadprogress_get_info() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $identifier = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'uploadprogress_get_info',
            1,
            'identifier'
        );
        $info = VmUploadprogress::getInfo($identifier);
        if (null === $info) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array($info);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'uploadprogress_get_info() is not implemented for JIT in this compiler build (issue #6386)'
        );
    }
}
