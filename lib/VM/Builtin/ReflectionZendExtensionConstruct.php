<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionZendExtension::__construct($name) — VM (#22248, ext/reflection/php_reflection.c). */
final class ReflectionZendExtensionConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionZendExtension::__construct() expects extension name');
        }
        $name = VmReflection::stringArg(
            $frame->calledArgs[1],
            'ReflectionZendExtension::__construct() name',
            1
        );
        if (!ModuleRegistry::zendExtensionLoaded($name)) {
            ReflectionSupport::throwReflectionException(
                'Zend Extension "'.$name.'" does not exist'
            );
        }
        $receiver = ReflectionSupport::requireReflectionZendExtension($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_ZEND_EXTENSION_NAME)->string($name);
        $receiver->constructed = true;
    }
}
