<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringBin2hex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * sodium_bin2hex() — binary to hex (php-src ext/sodium/libsodium.c; #3438, #21517/#24772, #26871).
 *
 * JIT/AOT: reuse {@see StringBin2hex} / `__compiler_bin2hex` (output matches Zend sodium_bin2hex;
 * NestedJIT-safe via Bin2hexJitHelper — no new C).
 */
final class sodium_bin2hex extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_bin2hex');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        // Z_PARAM_STR $string — soft-null DEP+coerce on forward profile (#21517/#24772, reverts #20196;
        // php-src ext/sodium — Zend 8.4 still deprecates null → '').
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'sodium_bin2hex', 0, 'string');
        $result = VmSodium::bin2hex($string);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        StringBin2hex::ensureLinked($context);

        $string = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                $this->getName(),
                0,
                'string'
            )
            : JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                $this->getName(),
                0,
                'string'
            );

        return $context->builder->call(
            $context->lookupFunction('__compiler_bin2hex'),
            $string
        );
    }
}
