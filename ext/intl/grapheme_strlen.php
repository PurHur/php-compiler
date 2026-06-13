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
 * grapheme_strlen() — grapheme cluster count (php-src ext/intl/grapheme; #5914).
 *
 * VM: {@see VmGrapheme}; JIT: compile-time fold via {@see JitGrapheme}.
 */
final class grapheme_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_strlen');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'grapheme_strlen', 1);
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_strlen',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmGrapheme::strlen($string);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'grapheme_strlen', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $folded = JitGrapheme::tryStrlenFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_strlen', 0, 'string');

        throw new \LogicException(
            'grapheme_strlen() JIT runtime lowering is deferred; use VM or compile-time literals (#5914)'
        );
    }
}
