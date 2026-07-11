<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSoundex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * soundex() — phonetic encoding (subset of PHP; issue #2416).
 *
 * VM: {@see VmString::soundex()}; JIT/AOT: {@see StringSoundex} + {@see SoundexJitHelper}.
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
        $string = self::vmStringArg($frame, 0, 'string');
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

        StringSoundex::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_soundex'),
            self::jitStringArg($context, $args[0], 1, 'string')
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireString($frame, $argIndex, 'soundex', $paramName)->toString();
        }

        return VmString::coerceStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            'soundex',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): Value {
        JitInternalStrictArg::requireString($context, $arg, 'soundex', $paramName, $argNumber);

        return JitStringBuiltinArg::lower(
            $context,
            $arg,
            'soundex',
            $argNumber - 1,
            $paramName
        );
    }
}
