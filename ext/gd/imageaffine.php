<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * imageaffine() — affine transform of GdImage (php-src ext/gd/gd.c; #20404).
 */
final class imageaffine extends Internal
{
    public function __construct()
    {
        parent::__construct('imageaffine');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('imageaffine() expects 2 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageaffine', 1);
        $affine = VmGd::coerceAffineMatrix($frame->calledArgs[1], 'imageaffine', 2);
        $clip = null;
        if ($argc >= 3) {
            $clipArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $clipArg->type) {
                $clip = VmGd::coerceAffineClipRect($clipArg, 'imageaffine', 3);
            }
        }
        $out = VmGd::affine($frame, $image, $affine, $clip);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageaffine() is VM-only in this compiler build (#20404)');
    }
}
