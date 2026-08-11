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
 * str_increment() — PHP 8.3: alphanumeric string increment.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment).
 */
final class str_increment extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_increment', 1);
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireStringStrict($arg, 'str_increment', 1, 'string');
        }
        $str = $arg->toString();

        if ('' === $str) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must not be empty');
        }
        if (!preg_match('/^[a-zA-Z0-9]+$/', $str)) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }

        $result = self::increment($str);

        if (null !== $frame->returnVar) {
            $frame->returnVar->string($result);
        }
    }

    public static function increment(string $str): string
    {
        $chars = str_split($str);
        $carry = true;
        for ($i = \count($chars) - 1; $i >= 0 && $carry; $i--) {
            $c = $chars[$i];
            if ($c >= '0' && $c <= '8') {
                $chars[$i] = \chr(\ord($c) + 1);
                $carry = false;
            } elseif ('9' === $c) {
                $chars[$i] = '0';
            } elseif ($c >= 'a' && $c <= 'y') {
                $chars[$i] = \chr(\ord($c) + 1);
                $carry = false;
            } elseif ('z' === $c) {
                $chars[$i] = 'a';
            } elseif ($c >= 'A' && $c <= 'Y') {
                $chars[$i] = \chr(\ord($c) + 1);
                $carry = false;
            } elseif ('Z' === $c) {
                $chars[$i] = 'A';
            }
        }
        if ($carry) {
            $first = $chars[0];
            if ($first >= '0' && $first <= '9') {
                array_unshift($chars, '1');
            } elseif ($first >= 'a' && $first <= 'z') {
                array_unshift($chars, 'a');
            } else {
                array_unshift($chars, 'A');
            }
        }

        return implode('', $chars);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('str_increment() is not supported by the JIT compiler in this build');
    }
}
