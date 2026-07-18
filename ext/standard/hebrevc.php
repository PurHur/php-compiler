<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * hebrevc() — visual Hebrew with newline conversion (php-src string.c).
 *
 * Removed in php-src 8.0; registered only when {@see \PHPCompiler\CompilerVersion::supportsHebrevc()}
 * (pre-8.0 language profile). VM: {@see VmHebrev::convertWithNewlines()}; JIT/AOT via {@see JitHebrevc}.
 */
final class hebrevc extends Internal
{
    public function __construct()
    {
        parent::__construct('hebrevc');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hebrevc() accepts one or two arguments in this compiler build');
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'hebrevc',
            0,
            'string'
        );
        $maxCharsPerLine = 0;
        if ($argc >= 2) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('hebrevc() max_chars_per_line must be an integer in this compiler build');
            }
            $maxCharsPerLine = $maxVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmHebrev::convertWithNewlines($string, $maxCharsPerLine));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitHebrevc::invoke($context, ...$args);
    }
}
