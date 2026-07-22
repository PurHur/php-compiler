<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionExtension::info() — VM (#22247, ext/reflection/php_reflection.c php_info_print_module). */
final class ReflectionExtensionInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionExtension($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_EXTENSION_NAME)->resolveIndirect();
        OutputBuffer::append(VmReflection::reflectionExtensionInfoText($nameVar->toString()));
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
