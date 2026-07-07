<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::implementsInterface() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassImplementsInterface extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('implementsInterface');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::implementsInterface() expects an interface name');
        }
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $ifaceName = ReflectionSupport::classNameFromReflectionClassArg(
            $frame->calledArgs[1],
            'implementsInterface',
            'interface'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                InterfaceCheck::entryImplements($entry, strtolower(ltrim($ifaceName, '\\')), $ctx)
            );
        }
    }
}
