<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSoundex;
use PHPCompiler\JIT\Context;
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
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'soundex', 1);
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
        if (!$this->requireExactJitArgCount($context, $args, 'soundex', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        $input = self::jitStringArg($context, $args[0], 1, 'string');
        StringSoundex::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_soundex'),
            $input
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'soundex', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21190; reverts #19243 TypeError).
        return VmString::coerceTrimFamilyStringArg(
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
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'soundex',
                $argNumber - 1,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'soundex',
            $argNumber - 1,
            $paramName
        );
    }
}
