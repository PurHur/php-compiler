<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native shell quoting for VM escapeshellarg()/escapeshellcmd() (php-src ext/standard/exec.c; #4861).
 *
 * JIT/AOT continue to use ProcessRuntime::__compiler_escapeshell*; this class removes host delegation on VM.
 */
final class VmEscapeshell
{
    /** Linux/non-Win32 php_escape_shell_cmd special bytes (matches ProcessRuntime::escapeshellcmdNeedsSlash). */
    private const CMD_ESCAPE_BYTES = "#&;`|*?~<>^()[]{}$\\\n\xFF";

    public static function escapeshellarg(string $argument): string
    {
        $l = \strlen($argument);
        $out = "'";
        for ($x = 0; $x < $l; ++$x) {
            $mbLen = self::mbLen($argument, $x, $l);
            if ($mbLen < 0) {
                continue;
            }
            if ($mbLen > 1) {
                $out .= \substr($argument, $x, $mbLen);
                $x += $mbLen - 1;

                continue;
            }

            $ch = $argument[$x];
            if ("'" === $ch) {
                $out .= "'\\''";
            } else {
                $out .= $ch;
            }
        }
        $out .= "'";

        return $out;
    }

    public static function escapeshellcmd(string $command): string
    {
        $l = \strlen($command);
        if (0 === $l) {
            return '';
        }

        $out = '';
        $closingQuoteAt = null;
        for ($x = 0; $x < $l; ++$x) {
            $mbLen = self::mbLen($command, $x, $l);
            if ($mbLen < 0) {
                continue;
            }
            if ($mbLen > 1) {
                $out .= \substr($command, $x, $mbLen);
                $x += $mbLen - 1;

                continue;
            }

            $ch = $command[$x];
            if ('"' === $ch || "'" === $ch) {
                if (null === $closingQuoteAt) {
                    $closePos = \strpos($command, $ch, $x + 1);
                    if (false !== $closePos) {
                        $closingQuoteAt = $closePos;
                    } else {
                        $out .= '\\';
                    }
                } elseif ($closingQuoteAt === $x) {
                    $closingQuoteAt = null;
                } else {
                    $out .= '\\';
                }
                $out .= $ch;

                continue;
            }

            if (self::cmdNeedsSlash($ch)) {
                $out .= '\\';
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function cmdNeedsSlash(string $ch): bool
    {
        return '' !== $ch && false !== \strpbrk($ch, self::CMD_ESCAPE_BYTES);
    }

    private static function findQuoteClose(string $str, int $x, int $l, string $quote): ?int
    {
        $pos = \strpos($str, $quote, $x + 1);
        if (false === $pos || $pos >= $l) {
            return null;
        }

        return $pos;
    }

    private static function mbLen(string $str, int $x, int $l): int
    {
        if ($x >= $l) {
            return 0;
        }

        $byte = \ord($str[$x]);
        if ($byte < 0x80) {
            return 1;
        }

        if (\function_exists('mb_strlen')) {
            $char = \substr($str, $x, 1);
            $len = @\mb_strlen($char, 'UTF-8');
            if (\is_int($len) && $len > 0) {
                $byteLen = \strlen(@\mb_substr($str, $x, 1, 'UTF-8') ?? $char);

                return $byteLen > 0 ? $byteLen : 1;
            }
        }

        return 1;
    }
}
