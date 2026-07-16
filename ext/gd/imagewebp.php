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
 * imagewebp() — emit WebP bytes (php-src ext/gd/gd.c; #6378).
 */
final class imagewebp extends Internal
{
    public function __construct()
    {
        parent::__construct('imagewebp');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('imagewebp() expects 1 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagewebp', 1);
        if ($argc >= 2) {
            $pathVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pathVar->type) {
                $path = VmString::coerceStringBuiltinArg($pathVar, 'imagewebp', 1, 'file');
                $frame->returnVar->bool(false !== VmFs::filePutContents($path, VmGd::encodedWebpBytes($image)));

                return;
            }
        }
        $frame->returnVar->bool(VmGd::writeWebpToOutput($frame, $image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagewebp() is VM-only in this compiler build (#6378)');
    }
}
