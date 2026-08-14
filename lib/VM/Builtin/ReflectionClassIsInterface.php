<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionClass::isInterface() — VM (#18335, ext/reflection/php_reflection.c). */
final class ReflectionClassIsInterface extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInterface');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_isInterface — ZEND_PARSE_PARAMETERS (0 args) (#31126)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::isInterface', 0);
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::reflectionClassIsInterface($entry));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!self::requireExactJitUserArgCount($context, $args, 'ReflectionClass::isInterface', 0)) {
            return self::jitArgcDummyReturn($context);
        }

        return ReflectionSetup::emitKindQuery($context, $args, 'isInterface');
    }
}
