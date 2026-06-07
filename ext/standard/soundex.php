<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * soundex() — phonetic encoding (subset of PHP; issue #2416).
 *
 * VM: {@see VmString::soundex()}; JIT/AOT: {@see JitSoundex}.
 */
final class soundex extends Internal
{
    public function __construct()
    {
        parent::__construct('soundex');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('soundex() requires exactly one argument in this compiler build');
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'soundex', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::soundex($string));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('soundex() requires exactly one argument in this compiler build');
        }

        return JitSoundex::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'soundex', 0, 'string')
        );
    }
}
