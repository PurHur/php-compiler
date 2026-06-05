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
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('soundex() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::soundex($arg->toString()));
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
            $this->jitString($context, $args[0], 'soundex() argument #1')
        );
    }
}
