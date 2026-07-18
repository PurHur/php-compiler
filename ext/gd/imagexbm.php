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

/** imagexbm() — emit XBM text (php-src ext/gd/gd.c; #20472). */
final class imagexbm extends Internal
{
    public function __construct()
    {
        parent::__construct('imagexbm');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('imagexbm() expects 2 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagexbm', 1);
        $pathVar = $frame->calledArgs[1]->resolveIndirect();
        $path = null;
        $name = 'image';
        if (Variable::TYPE_NULL !== $pathVar->type) {
            $path = VmString::coerceStringBuiltinArg($pathVar, 'imagexbm', 2, 'filename');
            $name = $path;
        }
        $foreground = null;
        if ($argc >= 3) {
            $fgVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $fgVar->type) {
                $foreground = VmMath::parseIntBuiltinArg($fgVar, 'imagexbm', 3, 'foreground_color');
            }
        }
        $bytes = VmGd::encodedXbmBytes($image, $foreground, $name);
        if (null !== $path) {
            $frame->returnVar->bool(false !== VmFs::filePutContents($path, $bytes));

            return;
        }
        $frame->returnVar->bool(VmGd::writeXbmToOutput($frame, $image, $foreground, $name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagexbm() is VM-only in this compiler build (#20472)');
    }
}
