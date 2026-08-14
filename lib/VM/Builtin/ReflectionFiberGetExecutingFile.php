<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFiber::getExecutingFile(): string|false — VM (#4609, ext/reflection/php_reflection.c). */
final class ReflectionFiberGetExecutingFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExecutingFile');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFiber_getExecutingFile — ZEND_PARSE_PARAMETERS_NONE (#30928)
        $this->requireExactUserArgCount($frame, 'ReflectionFiber::getExecutingFile', 0);
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiber = FiberTrace::fiberStateFromReflection($receiver);
        $file = FiberTrace::executingFile($fiber);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $file) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($file);
        }
    }
}
