<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::inNamespace() — VM PHP 8.6+ (#22662, php/php-src#20902). */
final class ReflectionConstantInNamespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('inNamespace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $name = ReflectionSupport::constantNameFromReflection($receiver);
            // php-src: zend_memrchr(name, '\\') → bool
            $frame->returnVar->bool(false !== strrpos($name, '\\'));
        }
    }
}
