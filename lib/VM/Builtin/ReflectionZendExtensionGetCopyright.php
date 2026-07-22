<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionZendExtension::getCopyright() — VM (#22248, ext/reflection/php_reflection.c). */
final class ReflectionZendExtensionGetCopyright extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCopyright');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionZendExtension($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_ZEND_EXTENSION_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionZendExtension missing name');
        }
        $meta = ModuleRegistry::getZendExtension($nameVar->toString());
        if (null === $meta) {
            throw new \LogicException('ReflectionZendExtension metadata missing');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($meta['copyright']);
        }
    }
}
