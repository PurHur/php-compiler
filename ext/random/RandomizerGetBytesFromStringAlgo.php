<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

/**
 * Shared getBytesFromString algorithm — php-src ext/random/randomizer.c (#19572, #19574).
 *
 * Used by VM {@see RandomizerGetBytesFromString} and user-script AOT compile-time eval.
 */
final class RandomizerGetBytesFromStringAlgo
{
    private const RANGE_ATTEMPTS = 50;

    /**
     * @param callable(int, int): int $range fn($min, $max): int
     * @param callable(): string      $generate
     */
    public static function compute(
        string $source,
        int $length,
        callable $range,
        callable $generate
    ): string {
        $sourceLength = \strlen($source);
        if ($sourceLength < 1) {
            throw new \ValueError(
                'Random\\Randomizer::getBytesFromString(): Argument #1 ($string) cannot be empty'
            );
        }
        if ($length < 1) {
            throw new \ValueError(
                'Random\\Randomizer::getBytesFromString(): Argument #2 ($length) must be greater than 0'
            );
        }
        $maxOffset = $sourceLength - 1;
        $out = '';
        if ($maxOffset > 0xff) {
            while (\strlen($out) < $length) {
                $offset = $range(0, $maxOffset);
                $out .= $source[$offset];
            }
        } else {
            $mask = $maxOffset;
            $mask |= $mask >> 1;
            $mask |= $mask >> 2;
            $mask |= $mask >> 4;
            $failures = 0;
            while (\strlen($out) < $length) {
                $chunk = $generate();
                $chunkLen = \strlen($chunk);
                for ($i = 0; $i < $chunkLen; ++$i) {
                    $offset = \ord($chunk[$i]) & $mask;
                    if ($offset > $maxOffset) {
                        if (++$failures > self::RANGE_ATTEMPTS) {
                            throw new \Random\BrokenRandomEngineError(
                                'Failed to generate an acceptable random number in '.self::RANGE_ATTEMPTS.' attempts'
                            );
                        }
                        continue;
                    }
                    $failures = 0;
                    $out .= $source[$offset];
                    if (\strlen($out) >= $length) {
                        break;
                    }
                }
            }
        }

        return \substr($out, 0, $length);
    }

    public static function computeFromMt19937(Mt19937Instance $engine, string $source, int $length): string
    {
        return self::compute(
            $source,
            $length,
            static fn (int $min, int $max): int => $engine->range($min, $max),
            static fn (): string => $engine->generate()
        );
    }
}
