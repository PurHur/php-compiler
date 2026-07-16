<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * imageavif() — emit AVIF bytes (php-src ext/gd/gd.c; #6378).
 */
final class imageavif extends Internal
{
    public function __construct()
    {
        parent::__construct('imageavif');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('imageavif() expects 1 to 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageavif', 1);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imageavif', 1, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, VmGd::encodedAvifBytes($image)));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeAvifToOutput($frame, $image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageavif() is VM-only in this compiler build (#6378)');
    }
}
