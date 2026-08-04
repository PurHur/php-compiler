<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmMath;
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
 * Shared VM wiring for sodium_pad()/sodium_unpad() (php-src ext/sodium/libsodium.c; #15532).
 */
abstract class SodiumPadFunction extends Internal
{
    abstract protected function invoke(string $string, int $blockSize): string;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'string');
        $blockSize = VmMath::parseIntBuiltinArgForFrame($frame, 1, $this->getName(), 2, 'block_size');
        $result = $this->invoke($string, $blockSize);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $string = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'string');
        $blockSize = $this->jitLong($context, $args[1], $this->getName().' block_size');

        return JitSodium::invokePad($context, $this->getName(), $string, $blockSize);
    }
}
