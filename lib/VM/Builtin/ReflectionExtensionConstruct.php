<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionExtension::__construct($name) — VM (#11462). */
final class ReflectionExtensionConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionExtension::__construct() expects extension name');
        }
        $name = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionExtension::__construct() name', 1);
        if (!ModuleRegistry::extensionLoaded($name)) {
            ReflectionSupport::throwReflectionException(
                'ReflectionExtension::__construct(): Extension '.$name.' does not exist'
            );
        }
        $receiver = ReflectionSupport::requireReflectionExtension($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_EXTENSION_NAME)->string($name);
        $receiver->constructed = true;
    }
}
