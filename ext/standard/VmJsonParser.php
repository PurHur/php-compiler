<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JSON parser for VM json_decode() — builds PHP values without host json_decode() (#4795).
 *
 * php-src ref: ext/json/json_scanner.c, json_parser.c
 */
final class VmJsonParser
{
    private const MAX_LEN = 8 * 1024 * 1024;

    private string $json;
    private int $len;
    private int $pos = 0;
    private int $depth = 0;
    private int $maxDepth;
    private bool $assoc;

    public function __construct(string $json, int $maxDepth, bool $assoc)
    {
        $this->json = $json;
        $this->len = \strlen($json);
        $this->maxDepth = $maxDepth;
        $this->assoc = $assoc;
    }

    public function atEnd(): bool
    {
        $this->skipWs();

        return $this->pos >= $this->len;
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    public function parseTop(): mixed
    {
        if (0 === $this->len || $this->len > self::MAX_LEN) {
            VmJson::setLastError(4);

            return null;
        }
        $this->skipWs();
        if ($this->pos >= $this->len) {
            VmJson::setLastError(4);

            return null;
        }

        return $this->parseValue();
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    private function parseValue(): mixed
    {
        if ($this->depth > $this->maxDepth) {
            VmJson::setLastError(1);

            return null;
        }
        $this->skipWs();
        if ($this->pos >= $this->len) {
            VmJson::setLastError(4);

            return null;
        }
        $c = $this->json[$this->pos];
        if ('"' === $c) {
            return $this->parseStringValue();
        }
        if ('{' === $c) {
            ++$this->depth;
            $result = $this->parseObjectValue();
            --$this->depth;

            return $result;
        }
        if ('[' === $c) {
            ++$this->depth;
            $result = $this->parseArrayValue();
            --$this->depth;

            return $result;
        }
        if ('-' === $c || ($c >= '0' && $c <= '9')) {
            return $this->parseNumberValue();
        }
        if ('t' === $c && $this->matchLiteral('true')) {
            return true;
        }
        if ('f' === $c && $this->matchLiteral('false')) {
            return false;
        }
        if ('n' === $c && $this->matchLiteral('null')) {
            return null;
        }
        VmJson::setLastError(4);

        return null;
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function parseObjectValue(): mixed
    {
        if (!$this->expect('{')) {
            VmJson::setLastError(4);

            return null;
        }
        $this->skipWs();
        if ($this->expect('}')) {
            return $this->assoc ? [] : new \stdClass();
        }
        $assocOut = [];
        $objectOut = new \stdClass();
        for (;;) {
            $key = $this->parseStringValue();
            if (!\is_string($key) || '' === $key) {
                VmJson::setLastError(4);

                return null;
            }
            if (!$this->expect(':')) {
                VmJson::setLastError(4);

                return null;
            }
            $value = $this->parseValue();
            if (null === $value && VmJson::lastError() !== 0) {
                return null;
            }
            if ($this->assoc) {
                $assocOut[$key] = $value;
            } else {
                $objectOut->{$key} = $value;
            }
            $this->skipWs();
            if ($this->pos >= $this->len) {
                VmJson::setLastError(4);

                return null;
            }
            if ('}' === $this->json[$this->pos]) {
                $this->pos++;

                return $this->assoc ? $assocOut : $objectOut;
            }
            if (',' !== $this->json[$this->pos]) {
                VmJson::setLastError(4);

                return null;
            }
            $this->pos++;
        }
    }

    /**
     * @return list<mixed>|null
     */
    private function parseArrayValue(): ?array
    {
        if (!$this->expect('[')) {
            VmJson::setLastError(4);

            return null;
        }
        $this->skipWs();
        if ($this->expect(']')) {
            return [];
        }
        $out = [];
        for (;;) {
            $value = $this->parseValue();
            if (null === $value && VmJson::lastError() !== 0) {
                return null;
            }
            $out[] = $value;
            $this->skipWs();
            if ($this->pos >= $this->len) {
                VmJson::setLastError(4);

                return null;
            }
            if (']' === $this->json[$this->pos]) {
                $this->pos++;

                return $out;
            }
            if (',' !== $this->json[$this->pos]) {
                VmJson::setLastError(4);

                return null;
            }
            $this->pos++;
        }
    }

    private function parseStringValue(): ?string
    {
        if (!$this->expect('"')) {
            VmJson::setLastError(4);

            return null;
        }
        $out = '';
        while ($this->pos < $this->len) {
            $c = $this->json[$this->pos++];
            if ('"' === $c) {
                return $out;
            }
            if ('\\' !== $c) {
                $out .= $c;
                continue;
            }
            if ($this->pos >= $this->len) {
                VmJson::setLastError(4);

                return null;
            }
            $esc = $this->json[$this->pos++];
            if (\in_array($esc, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't'], true)) {
                $out .= match ($esc) {
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'b' => "\x08",
                    'f' => "\x0C",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $esc,
                };
                continue;
            }
            if ('u' === $esc) {
                if ($this->pos + 4 > $this->len) {
                    VmJson::setLastError(4);

                    return null;
                }
                $hex = \substr($this->json, $this->pos, 4);
                if (!ctype_xdigit($hex)) {
                    VmJson::setLastError(4);

                    return null;
                }
                $this->pos += 4;
                $out .= self::unicodeEscape((int) hexdec($hex));
                continue;
            }
            VmJson::setLastError(4);

            return null;
        }
        VmJson::setLastError(4);

        return null;
    }

    /**
     * @return int|float|null
     */
    private function parseNumberValue(): int|float|null
    {
        $start = $this->pos;
        if ('-' === $this->json[$this->pos]) {
            $this->pos++;
        }
        if ($this->pos >= $this->len || $this->json[$this->pos] < '0' || $this->json[$this->pos] > '9') {
            VmJson::setLastError(4);

            return null;
        }
        if ('0' === $this->json[$this->pos]) {
            $this->pos++;
        } else {
            while ($this->pos < $this->len && $this->json[$this->pos] >= '0' && $this->json[$this->pos] <= '9') {
                $this->pos++;
            }
        }
        $isFloat = false;
        if ($this->pos < $this->len && '.' === $this->json[$this->pos]) {
            $isFloat = true;
            $this->pos++;
            if ($this->pos >= $this->len || $this->json[$this->pos] < '0' || $this->json[$this->pos] > '9') {
                VmJson::setLastError(4);

                return null;
            }
            while ($this->pos < $this->len && $this->json[$this->pos] >= '0' && $this->json[$this->pos] <= '9') {
                $this->pos++;
            }
        }
        if ($this->pos < $this->len && ('e' === $this->json[$this->pos] || 'E' === $this->json[$this->pos])) {
            $isFloat = true;
            $this->pos++;
            if ($this->pos < $this->len && ('+' === $this->json[$this->pos] || '-' === $this->json[$this->pos])) {
                $this->pos++;
            }
            if ($this->pos >= $this->len || $this->json[$this->pos] < '0' || $this->json[$this->pos] > '9') {
                VmJson::setLastError(4);

                return null;
            }
            while ($this->pos < $this->len && $this->json[$this->pos] >= '0' && $this->json[$this->pos] <= '9') {
                $this->pos++;
            }
        }
        $numStr = \substr($this->json, $start, $this->pos - $start);
        if ($isFloat) {
            return (float) $numStr;
        }

        return (int) $numStr;
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

    private function matchLiteral(string $lit): bool
    {
        $litLen = \strlen($lit);
        if ($this->pos + $litLen > $this->len) {
            return false;
        }
        if (0 !== strncmp(\substr($this->json, $this->pos, $litLen), $lit, $litLen)) {
            return false;
        }
        $this->pos += $litLen;

        return true;
    }

    private static function unicodeEscape(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return \chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return \chr(0xC0 | ($codepoint >> 6))
                .\chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return \chr(0xE0 | ($codepoint >> 12))
                .\chr(0x80 | (($codepoint >> 6) & 0x3F))
                .\chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18))
            .\chr(0x80 | (($codepoint >> 12) & 0x3F))
            .\chr(0x80 | (($codepoint >> 6) & 0x3F))
            .\chr(0x80 | ($codepoint & 0x3F));
    }
}
