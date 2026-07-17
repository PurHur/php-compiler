<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

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
 * sodium_compare() — constant-time buffer compare (-1/0/1) (php-src ext/sodium/libsodium.c; #20081).
 */
final class sodium_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_compare');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $string1 = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'string1');
        $string2 = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'string2');
        $result = VmSodium::compare($string1, $string2);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $string1 = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'string1');
        $string2 = JitStringBuiltinArg::lower($context, $args[1], $this->getName(), 1, 'string2');

        return JitSodium::invokeCompare($context, $string1, $string2);
    }
}
