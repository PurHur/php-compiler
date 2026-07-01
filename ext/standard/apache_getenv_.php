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
 * apache_getenv() — Apache subprocess environment lookup (#11626).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(apache_getenv)
 */
final class apache_getenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('apache_getenv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'apache_getenv() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $variable = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'apache_getenv', 0, 'variable');
        $walkToTop = false;
        if (2 === $argc) {
            $walkToTop = VmApache::coerceWalkToTopArg($frame->calledArgs[1], 'apache_getenv', 1);
        }

        $result = VmApache::getenv($variable, $walkToTop);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'apache_getenv() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }

        $i8 = $context->getTypeFromString('int8');
        $localOnly = $i8->constInt(0, false);
        if (2 === $argc) {
            $walkToTop = JitBoolArg::lower($context, $args[1], 'apache_getenv() walk_to_top');
            unset($walkToTop);
        }

        return JitEnv::getenv(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'apache_getenv', 0, 'variable'),
            $localOnly
        );
    }
}
