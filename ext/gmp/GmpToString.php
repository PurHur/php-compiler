<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** GMP::__toString() (php-src ext/gmp/gmp.c; issue #3341). */
final class GmpToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmGmpObject::requireGmp($frame->calledArgs[0], 'GMP::__toString', 0, 'gmp');
        $frame->returnVar->string(VmGmp::strval(VmGmp::objectToSignedDecimal($object)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('GMP::__toString() is not supported for JIT/AOT in this compiler build (issue #3341)');
    }
}
