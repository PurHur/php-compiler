<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionExtension::getINIEntries() — VM (#22247, ext/reflection/php_reflection.c). */
final class ReflectionExtensionGetINIEntries extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getINIEntries');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionExtension($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_EXTENSION_NAME)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(
                VmIni::reflectionIniEntries($ctx, $nameVar->toString())
            );
        }
    }
}
