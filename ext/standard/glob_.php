<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** glob() — path pattern matching (VM via VmFsGlob; JIT via StringFsGlobVecJit, #4859/#7405). */
final class glob_ extends Internal
{
    public function __construct()
    {
        parent::__construct('glob');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'glob', 1, 2);
        // php-src file.c php_glob — pattern null DEP+coerce on 8.4 (#20554, #21366, #29659), not Z_PARAM_STR TypeError.
        // VmString argIndex is 0-based (helpers add +1 for the user-facing parameter number).
        $pattern = VmString::stringBuiltinArgForFrame($frame, 0, 'glob', 0, 'pattern', false);
        $flags = 0;
        if (isset($frame->calledArgs[1])) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'glob', 2, 'flags');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = VmFsGlob::glob($pattern, $flags, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'glob', 1, 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $i32 = $context->getTypeFromString('int32');
        $flags = $i32->constInt(0, false);
        if (isset($args[1])) {
            $flags = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'glob() flags'),
                $i32
            );
        }

        $pattern = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'glob', 0, 'pattern');
        StringFsGlob::ensureLinked($context);

        return JitFsGlob::glob($context, $pattern, $flags);
    }
}
