<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JSON syntax scanner for VM json_validate() — mirrors StringJsonDecodeJit / former phpc_json_decode.c.
 *
 * php-src ref: ext/json/json_scanner.c (lexer-only validation subset).
 */
final class VmJsonScanner
{
    private const MAX_LEN = 8 * 1024 * 1024;

    /** @var 1 valid, 0 syntax error, -1 depth exceeded (matches __compiler_json_validate) */
    public const RESULT_VALID = 1;
    public const RESULT_SYNTAX = 0;
    public const RESULT_DEPTH = -1;

    private string $json;
    private int $len;
    private int $pos = 0;
    private int $depth = 0;
    private int $maxDepth;
    private int $flags;

    private function __construct(string $json, int $maxDepth, int $flags = 0)
    {
        $this->json = $json;
        $this->len = \strlen($json);
        $this->maxDepth = $maxDepth;
        $this->flags = $flags;
    }

    public static function validate(string $json, int $maxDepth, int $flags = 0): int
    {
        $len = \strlen($json);
        if (0 === $len || $len > self::MAX_LEN) {
            return self::RESULT_SYNTAX;
        }
        $scanner = new self($json, $maxDepth, $flags);
        VmJson::setLastError(0);
        if (!$scanner->parseTop()) {
            if (1 === VmJson::lastError()) {
                return self::RESULT_DEPTH;
            }

            return self::RESULT_SYNTAX;
        }
        $scanner->skipWs();
        if ($scanner->pos !== $scanner->len) {
            return self::RESULT_SYNTAX;
        }

        return self::RESULT_VALID;
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->json[$this->pos];
            if (' ' !== $c && "\t" !== $c && "\n" !== $c && "\r" !== $c) {
                break;
            }
            $this->pos++;
        }
    }

    private function expect(string $ch): bool
    {
        $this->skipWs();
        if ($this->pos >= $this->len || $this->json[$this->pos] !== $ch) {
            return false;
        }
        $this->pos++;

        return true;
    }

    private function parseString(): bool
    {
        if (!$this->expect('"')) {
            return false;
        }
        $start = $this->pos;
        while ($this->pos < $this->len) {
            $c = $this->json[$this->pos++];
            if ('"' === $c) {
                if (!VmJsonFlags::ignoreInvalidUtf8($this->flags)) {
                    $content = \substr($this->json, $start, $this->pos - 1 - $start);
                    if (!VmJsonUtf8::isValidJsonStringContent($content)) {
                        return false;
                    }
                }

                return true;
            }
            if ('\\' === $c && $this->pos < $this->len) {
                $esc = $this->json[$this->pos++];
                if (\in_array($esc, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't'], true)) {
                    continue;
                }
                if ('u' === $esc) {
                    if ($this->pos + 4 > $this->len) {
                        return false;
                    }
                    $hex = \substr($this->json, $this->pos, 4);
                    if (4 !== \strspn($hex, '0123456789abcdefABCDEF')) {
                        return false;
                    }
                    $this->pos += 4;
                    continue;
                }

                return false;
            }
        }

        return false;
    }

    private function parseNumber(): bool
    {
        if ($this->pos >= $this->len) {
            return false;
        }
        if ('-' === $this->json[$this->pos]) {
            $this->pos++;
        }
        if ($this->pos >= $this->len || $this->json[$this->pos] < '0' || $this->json[$this->pos] > '9') {
            return false;
        }
        while ($this->pos < $this->len && $this->json[$this->pos] >= '0' && $this->json[$this->pos] <= '9') {
            $this->pos++;
        }
        if ($this->pos < $this->len && '.' === $this->json[$this->pos]) {
            $this->pos++;
            while ($this->pos < $this->len && $this->json[$this->pos] >= '0' && $this->json[$this->pos] <= '9') {
                $this->pos++;
            }
        }
        if ($this->pos < $this->len && ('e' === $this->json[$this->pos] || 'E' === $this->json[$this->pos])) {
            $this->pos++;
            if ($this->pos < $this->len && ('+' === $this->json[$this->pos] || '-' === $this->json[$this->pos])) {
                $this->pos++;
            }
            while ($this->pos < $this->len && $this->json[$this->pos] >= '0' && $this->json[$this->pos] <= '9') {
                $this->pos++;
            }
        }

        return true;
    }

    private function parseLiteral(string $lit): bool
    {
        $litLen = \strlen($lit);
        if ($this->pos + $litLen > $this->len) {
            return false;
        }
        if (0 !== \strncmp(\substr($this->json, $this->pos, $litLen), $lit, $litLen)) {
            return false;
        }
        $this->pos += $litLen;

        return true;
    }

    private function parseValue(): bool
    {
        $this->skipWs();
        if ($this->pos >= $this->len) {
            return false;
        }
        $c = $this->json[$this->pos];
        if ('"' === $c) {
            return $this->parseString();
        }
        // Match VmJsonParser / php-src: depth+1 >= maxDepth before entering {} / [].
        if ('{' === $c) {
            if ($this->depth + 1 >= $this->maxDepth) {
                VmJson::setLastError(1);

                return false;
            }
            ++$this->depth;
            if (!$this->parseObject()) {
                --$this->depth;

                return false;
            }
            --$this->depth;

            return true;
        }
        if ('[' === $c) {
            if ($this->depth + 1 >= $this->maxDepth) {
                VmJson::setLastError(1);

                return false;
            }
            ++$this->depth;
            if (!$this->parseArray()) {
                --$this->depth;

                return false;
            }
            --$this->depth;

            return true;
        }
        if ('-' === $c || ($c >= '0' && $c <= '9')) {
            return $this->parseNumber();
        }
        if ('t' === $c) {
            return $this->parseLiteral('true');
        }
        if ('f' === $c) {
            return $this->parseLiteral('false');
        }
        if ('n' === $c) {
            return $this->parseLiteral('null');
        }

        return false;
    }

    private function parseObject(): bool
    {
        if (!$this->expect('{')) {
            return false;
        }
        $this->skipWs();
        if ($this->expect('}')) {
            return true;
        }
        for (;;) {
            $keyStart = $this->pos;
            if (!$this->parseString()) {
                return false;
            }
            if ($keyStart + 1 === $this->pos) {
                return false;
            }
            if (!$this->expect(':')) {
                return false;
            }
            if (!$this->parseValue()) {
                return false;
            }
            $this->skipWs();
            if ($this->pos >= $this->len) {
                return false;
            }
            if ('}' === $this->json[$this->pos]) {
                $this->pos++;

                return true;
            }
            if (',' !== $this->json[$this->pos]) {
                return false;
            }
            $this->pos++;
        }
    }

    private function parseArray(): bool
    {
        if (!$this->expect('[')) {
            return false;
        }
        $this->skipWs();
        if ($this->expect(']')) {
            return true;
        }
        for (;;) {
            if (!$this->parseValue()) {
                return false;
            }
            $this->skipWs();
            if ($this->pos >= $this->len) {
                return false;
            }
            if (']' === $this->json[$this->pos]) {
                $this->pos++;

                return true;
            }
            if (',' !== $this->json[$this->pos]) {
                return false;
            }
            $this->pos++;
        }
    }

    private function parseTop(): bool
    {
        // Must go through parseValue() so top-level {} / [] honor $depth
        // (php-src php_json_parser / VmJsonParser::parseTop).
        return $this->parseValue();
    }
}
