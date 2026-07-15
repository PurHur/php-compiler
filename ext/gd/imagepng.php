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
 * imagepng() — emit stored PNG bytes (php-src ext/gd/gd.c; #6215).
 */
final class imagepng extends Internal
{
    public function __construct()
    {
        parent::__construct('imagepng');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('imagepng() expects 1 to 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagepng', 1);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagepng', 1, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, VmGd::encodedBytes($image)));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writePngToOutput($frame, $image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagepng() is VM-only in this compiler build (#6215)');
    }
}
