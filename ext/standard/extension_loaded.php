<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * extension_loaded() — registered extension probe (ext/standard/info.c parity, #3204).
 *
 * Z_PARAM_STR $extension — null TypeError on PHP_COMPILER_PROFILE=8.4 (#20254).
 */
final class extension_loaded extends Internal
{
    public function __construct()
    {
        parent::__construct('extension_loaded');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('extension_loaded() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR — null TypeError on PROFILE=8.4 (#20254, ext/standard/info.c).
        $name = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'extension_loaded', 0, 'extension');
        $frame->returnVar->bool(VmInfo::extension_loaded($name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('extension_loaded() requires exactly one argument');
        }
        return JitInfo::extension_loaded($context, $args[0]);
    }
}
