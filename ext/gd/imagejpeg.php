<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * imagejpeg() — emit JPEG bytes (php-src ext/gd/gd.c; #20458).
 */
final class imagejpeg extends Internal
{
    public function __construct()
    {
        parent::__construct('imagejpeg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('imagejpeg() expects 1 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagejpeg', 1);
        $quality = 75;
        if ($argc >= 3) {
            $quality = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'imagejpeg', 3, 'quality');
        }
        $bytes = VmGd::encodedJpegBytes($image, $quality);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagejpeg', 1, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, $bytes));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeJpegToOutput($frame, $image, $quality));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagejpeg() is VM-only in this compiler build (#20458)');
    }
}
