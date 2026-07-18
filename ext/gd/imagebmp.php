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
 * imagebmp() — emit BMP bytes (php-src ext/gd/gd.c; #20417).
 */
final class imagebmp extends Internal
{
    public function __construct()
    {
        parent::__construct('imagebmp');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('imagebmp() expects 1 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagebmp', 1);
        $compressed = true;
        if ($argc >= 3) {
            $compressed = VmMath::parseBoolBuiltinArg($frame->calledArgs[2], 'imagebmp', 3, 'compressed');
        }
        $bytes = VmGd::encodedBmpBytes($image, $compressed);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagebmp', 1, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, $bytes));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeBmpToOutput($frame, $image, $compressed));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagebmp() is VM-only in this compiler build (#20417)');
    }
}
