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

/** imagegd2() — emit GD2 .gd2 bytes (php-src ext/gd/gd.c; #20502). */
final class imagegd2 extends Internal
{
    public function __construct()
    {
        parent::__construct('imagegd2');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('imagegd2() expects 1 to 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagegd2', 1);
        $chunkSize = VmGdGd::GD2_CHUNKSIZE;
        $mode = VmGdGd::GD2_FMT_RAW;
        if ($argc >= 3) {
            $chunkSize = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'imagegd2', 3, 'chunk_size');
        }
        if ($argc >= 4) {
            $mode = VmMath::parseIntBuiltinArg($frame->calledArgs[3], 'imagegd2', 4, 'mode');
        }
        $bytes = VmGd::encodedGd2Bytes($image, $chunkSize, $mode);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagegd2', 2, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, $bytes));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeGd2ToOutput($frame, $image, $chunkSize, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagegd2() is VM-only in this compiler build (#20502)');
    }
}
