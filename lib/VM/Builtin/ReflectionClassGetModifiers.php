<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionClass::getModifiers() — VM (#18335, ext/reflection/php_reflection.c). */
final class ReflectionClassGetModifiers extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getModifiers');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_getModifiers — ZEND_PARSE_PARAMETERS (0 args) (#31126)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getModifiers', 0);
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmReflection::classEntryToReflectionModifiers($entry));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!self::requireExactJitUserArgCount($context, $args, 'ReflectionClass::getModifiers', 0)) {
            return self::jitArgcDummyReturn($context);
        }

        return ReflectionSetup::emitKindQuery($context, $args, 'getModifiers', true);
    }
}
