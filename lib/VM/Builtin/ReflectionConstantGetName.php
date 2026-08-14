<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getName() — VM (#3354). */
final class ReflectionConstantGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        // php-src: zim_ReflectionClassConstant_getName / ReflectionConstant — 0 args (#30896)
        $this->requireExactUserArgCount($frame, $receiver->class->name.'::getName', 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::constantNameFromReflection($receiver));
        }
    }
}
