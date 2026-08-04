<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** php-in-PHP JIT/AOT helper for sodium_pad()/sodium_unpad() (#27687). */
final class SodiumPadJitHelper
{
    public static function pad(string $string, int $blockSize): string
    {
        if ($blockSize <= 0) {
            throw new \SodiumException('sodium_pad(): Argument #2 ($block_size) must be greater than 0');
        }
        $len = \strlen($string);
        $padLen = $blockSize - ($len % $blockSize);
        if (0 === $padLen) {
            $padLen = $blockSize;
        }
        $padTail = '';
        for ($i = 1; $i < $padLen; ++$i) {
            $padTail .= "\0";
        }

        return $string."\x80".$padTail;
    }

    public static function unpad(string $string, int $blockSize): string
    {
        if ($blockSize <= 0) {
            throw new \SodiumException('sodium_unpad(): Argument #2 ($block_size) must be greater than 0');
        }
        $len = \strlen($string);
        if ($len < $blockSize) {
            throw new \SodiumException('sodium_unpad(): Argument #1 ($string) must be at least as long as the block size');
        }
        if (0 !== ($len % $blockSize)) {
            throw new \SodiumException('sodium_unpad(): padding is invalid');
        }
        for ($idx = $len - 1; $idx >= 0; --$idx) {
            $byte = \ord($string[$idx]);
            if (0x80 === $byte) {
                return \substr($string, 0, $idx);
            }
            if (0 !== $byte) {
                throw new \SodiumException('sodium_unpad(): padding is invalid');
            }
        }

        throw new \SodiumException('sodium_unpad(): padding is invalid');
    }
}
