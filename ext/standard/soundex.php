<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSoundex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * soundex() — phonetic encoding (subset of PHP; issue #2416).
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
            throw new \LogicException('soundex() requires exactly one argument');
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('soundex() argument must be a string in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::soundex($arg->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('soundex() requires exactly one argument');
        }
        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal) {
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::soundex($literal))
                )
            );
        }
        StringSoundex::ensureLinked($context);
        $str = $this->jitString($context, $args[0], 'soundex() argument #1');
        $data = $this->stringDataPtr($context, $str);

        return $context->builder->call($context->lookupFunction('__compiler_soundex'), $data);
    }
}
