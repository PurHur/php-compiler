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
 * imagegif() — emit GIF bytes (php-src ext/gd/gd.c; #20458).
 */
final class imagegif extends Internal
{
    public function __construct()
    {
        parent::__construct('imagegif');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('imagegif() expects 1 to 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagegif', 1);
        $bytes = VmGd::encodedGifBytes($image);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagegif', 1, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, $bytes));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeGifToOutput($frame, $image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagegif() is VM-only in this compiler build (#20458)');
    }
}
