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

/** imagewbmp() — emit WBMP bytes (php-src ext/gd/gd.c; #20472). */
final class imagewbmp extends Internal
{
    public function __construct()
    {
        parent::__construct('imagewbmp');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('imagewbmp() expects 1 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagewbmp', 1);
        $foreground = null;
        if ($argc >= 3) {
            $fgVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $fgVar->type) {
                $foreground = VmMath::parseIntBuiltinArg($fgVar, 'imagewbmp', 3, 'foreground_color');
            }
        }
        $bytes = VmGd::encodedWbmpBytes($image, $foreground);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagewbmp', 2, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, $bytes));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeWbmpToOutput($frame, $image, $foreground));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagewbmp() is VM-only in this compiler build (#20472)');
    }
}
