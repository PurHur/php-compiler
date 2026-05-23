<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** strchr() is a PHP alias of strstr() (not libc strchr). */
final class strchr extends Internal
{
    private static ?strstr $delegate = null;

    private static function delegate(): strstr
    {
        return self::$delegate ??= new strstr();
    }

    public function execute(Frame $frame): void
    {
        self::delegate()->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strchr() requires two or three arguments in this compiler build');
        }
        $before = null;
        if (3 === $argc) {
            $before = $this->jitBool($context, $args[2], 'strchr() before_needle');
        }

        return JitStrstr::find(
            $context,
            $this->jitString($context, $args[0], 'strchr() argument #1'),
            $this->jitString($context, $args[1], 'strchr() argument #2'),
            $before
        );
    }
}
