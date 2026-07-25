<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrcoll;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strcoll() — locale-aware string comparison (JIT via StrcollJitHelper PHP #13566).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll)
 */
final class strcoll extends Internal
{
    public function __construct()
    {
        parent::__construct('strcoll');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'strcoll', 2);
        $a = self::vmStringArg($frame, 0, 'string1');
        $b = self::vmStringArg($frame, 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmLocaleCollate::strcoll($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'strcoll', 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        StringStrcoll::ensureLinked($context);
        $p0 = $this->stringDataPtr($context, self::jitStringArg($context, $args[0], 0, 'string1'));
        $p1 = $this->stringDataPtr($context, self::jitStringArg($context, $args[1], 1, 'string2'));
        $fn = $context->lookupFunction('strcoll');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strcoll', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21317, peers strcmp #21190).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strcoll',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'strcoll',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'strcoll',
            $argIndex,
            $paramName
        );
    }
}
