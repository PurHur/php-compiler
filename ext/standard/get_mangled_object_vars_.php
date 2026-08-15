<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_mangled_object_vars() — debug property map with mangled keys (issue #3497).
 *
 * php-src: ext/standard/var.c — PHP_FUNCTION(get_mangled_object_vars)
 */
final class get_mangled_object_vars_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_mangled_object_vars');
    }

    public function execute(Frame $frame): void
    {
        // php-src Zend/zend_builtin_functions.c — ArgumentCountError (#30575).
        $this->requireExactArgCount($frame, 'get_mangled_object_vars', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            VmReflection::getMangledObjectVars($frame->calledArgs[0], $frame)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #30575.
        if (!$this->requireExactJitArgCount($context, $args, 'get_mangled_object_vars', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitGetObjectVars::invoke($context, $args[0], true);
    }
}
