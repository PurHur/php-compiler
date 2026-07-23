<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getExtension() — VM PHP 8.5+ (#21551, #22662, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetExtension extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExtension');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $ext = ReflectionSupport::globalReflectionConstantExtensionName($ctx, $receiver);
        if (null === $ext) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object(ReflectionSupport::newReflectionExtensionObject($ctx, $ext));
    }
}
