<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * spl_autoload_call() — invoke registered autoload callbacks for a class (issue #3486).
 *
 * php-src: ext/spl/php_spl.c — PHP_FUNCTION(spl_autoload_call)
 */
final class spl_autoload_call extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_call');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('spl_autoload_call() requires exactly one argument');
        }
        $ctx = VmReflection::requireContext($frame);
        // Z_PARAM_STR — Zend stub `$class`; caller strict_types → TypeError on null (#29820).
        $className = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'spl_autoload_call',
            0,
            'class'
        );
        VmSplAutoload::runStack($ctx, $className);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('spl_autoload_call() requires exactly one argument');
        }

        return JitSplAutoload::dispatch($context, $args[0]);
    }
}
