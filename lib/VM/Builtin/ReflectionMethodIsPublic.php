<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isPublic() — VM (#7116, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsPublic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPublic');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_isPublic — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::isPublic', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $flags = ReflectionSupport::reflectedMethodCfgFlags($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(MethodVisibility::isPublic($flags));
        }
    }
}
