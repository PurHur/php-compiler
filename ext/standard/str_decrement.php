<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * str_decrement() — PHP 8.3: alphanumeric string decrement.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_decrement).
 */
final class str_decrement extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_decrement', 1);
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireStringStrict($arg, 'str_decrement', 1, 'string');
        }
        $str = $arg->toString();

        if ('' === $str) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must not be empty');
        }
        if (!preg_match('/^[a-zA-Z0-9]+$/', $str)) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }

        $result = self::decrement($str);

        if (null !== $frame->returnVar) {
            $frame->returnVar->string($result);
        }
    }

    public static function decrement(string $str): string
    {
        // php-src: leading '0' is always out of range
        if ($str[0] === '0') {
            throw new \ValueError('str_decrement(): Argument #1 ($string) "' . $str . '" is out of decrement range');
        }

        $chars = str_split($str);
        $len = \count($chars);
        $carry = true;
        $i = $len - 1;

        do {
            $c = $chars[$i];
            if ('a' !== $c && 'A' !== $c && '0' !== $c) {
                $chars[$i] = \chr(\ord($c) - 1);
                $carry = false;
            } else {
                $carry = true;
                if ('0' === $c) {
                    $chars[$i] = '9';
                } else {
                    // 'a' -> 'z' or 'A' -> 'Z' (add 25)
                    $chars[$i] = \chr(\ord($c) + 25);
                }
            }
        } while ($carry && $i-- > 0);

        // php-src: strip leading char when carry propagated past pos 0
        // OR when leading is '0' and len > 1
        if ($carry || ('0' === $chars[0] && $len > 1)) {
            if (1 === $len) {
                throw new \ValueError('str_decrement(): Argument #1 ($string) "' . $str . '" is out of decrement range');
            }
            array_shift($chars);
        }

        return implode('', $chars);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('str_decrement() is not supported by the JIT compiler in this build');
    }
}
