<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** extract() — import string-keyed array variables into the caller scope (VM + JIT). */
final class extract_ extends Internal
{
    public function __construct()
    {
        parent::__construct('extract');
    }

    public function execute(Frame $frame): void
    {
        $count = VmScope::extract($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 3) → ArgumentCountError (#31420).
        if (!$this->requireArgCountRangeJit($context, $args, 'extract', 1, 3)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'extract');
        $flags = 2 <= \count($args) ? $args[1] : null;
        $prefix = 3 === \count($args) ? $args[2] : null;

        return ScopeBuiltinHelper::extract($context, $args[0], $flags, $prefix);
    }
}
