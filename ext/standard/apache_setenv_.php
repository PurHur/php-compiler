<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * apache_setenv() — Apache subprocess environment assignment (#11626).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(apache_setenv)
 */
final class apache_setenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('apache_setenv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'apache_setenv() expects 2 or 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $variable = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'apache_setenv', 0, 'variable');
        $value = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'apache_setenv', 1, 'value');
        $walkToTop = false;
        if (3 === $argc) {
            $walkToTop = VmApache::coerceWalkToTopArg($frame->calledArgs[2], 'apache_setenv', 2);
        }

        $frame->returnVar->bool(VmApache::setenv($variable, $value, $walkToTop));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'apache_setenv() expects 2 or 3 arguments, %d given',
                $argc
            ));
        }
        if (3 === $argc) {
            $walkToTop = JitBoolArg::lower($context, $args[2], 'apache_setenv() walk_to_top');
            unset($walkToTop);
        }

        return JitEnv::apacheSetenv(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'apache_setenv', 0, 'variable'),
            JitStringBuiltinArg::lower($context, $args[1], 'apache_setenv', 1, 'value')
        );
    }
}
