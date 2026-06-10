<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * grapheme_str_contains() — grapheme-cluster substring test (php-src ext/intl/grapheme; #7128).
 *
 * VM only — JIT/AOT lowering not implemented in this compiler build.
 */
final class grapheme_str_contains extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_str_contains');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'grapheme_str_contains', 2);
        $haystack = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_str_contains',
            0,
            'haystack'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_str_contains',
            1,
            'needle'
        );
        $result = VmGrapheme::strContains($haystack, $needle);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'grapheme_str_contains', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_str_contains', 0, 'haystack');
        JitStringBuiltinArg::lower($context, $args[1], 'grapheme_str_contains', 1, 'needle');

        throw new \LogicException(
            'grapheme_str_contains() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
