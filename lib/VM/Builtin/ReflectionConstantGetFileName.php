<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getFileName() — VM PHP 8.5+ (#21551, #22662, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetFileName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFileName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $file = ReflectionSupport::globalReflectionConstantFileName($ctx, $receiver);
        if (null === $file) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($file);
    }
}
