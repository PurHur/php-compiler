<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native unserialize() scalar/array decoder — pairs with {@see VmSerializeFormat} (issue #8191).
 *
 * php-src: ext/standard/var_unserializer.c
 */
final class VmUnserializeFormat
{
    private const DEFAULT_MAX_DEPTH = 4096;

    private int $pos = 0;

    private readonly int $length;

    private function __construct(
        private readonly string $payload,
        private readonly int $maxDepth,
    ) {
        $this->length = \strlen($payload);
    }

    /**
     * @param array<string, mixed>|null $options
     *
     * @return array<mixed>|bool|float|int|null|string|false
     */
    public static function decodePayload(string $payload, ?array $options = null): mixed
    {
        if ('' === $payload) {
            return false;
        }
        $maxDepth = self::DEFAULT_MAX_DEPTH;
        if (null !== $options && \array_key_exists('max_depth', $options)) {
            $maxDepth = (int) $options['max_depth'];
        }

        $parser = new self($payload, $maxDepth);
        $value = $parser->parseValue(0);
        if (false === $value || $parser->pos !== $parser->length) {
            return false;
        }

        return $value;
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|false
     */
    private function parseValue(int $depth): mixed
    {
        if ($this->pos >= $this->length) {
            return false;
        }

        $type = $this->payload[$this->pos];
        return match ($type) {
            'N' => $this->parseNull(),
            'b' => $this->parseBool(),
            'i' => $this->parseInt(),
            'd' => $this->parseDouble(),
            's' => $this->parseString(),
            'a' => $this->parseArray($depth),
            default => false,
        };
    }

    /** @return null|false */
    private function parseNull(): mixed
    {
        if (!$this->expect('N;')) {
            return false;
        }

        return null;
    }

    /** @return bool|false */
    private function parseBool(): mixed
    {
        if (!$this->expect('b:')) {
            return false;
        }
        $digit = $this->readDigit();
        if (null === $digit || !$this->expect(';')) {
            return false;
        }

        return 1 === $digit;
    }

    /** @return int|false */
    private function parseInt(): mixed
    {
        if (!$this->expect('i:')) {
            return false;
        }
        $number = $this->readSignedInteger();
        if (null === $number || !$this->expect(';')) {
            return false;
        }

        return $number;
    }

    /** @return float|false */
    private function parseDouble(): mixed
    {
        if (!$this->expect('d:')) {
            return false;
        }
        $start = $this->pos;
        while ($this->pos < $this->length && ';' !== $this->payload[$this->pos]) {
            ++$this->pos;
        }
        if ($this->pos >= $this->length) {
            return false;
        }
        $literal = \substr($this->payload, $start, $this->pos - $start);
        ++$this->pos;

        return match ($literal) {
            'NAN' => \NAN,
            'INF' => \INF,
            '-INF' => -\INF,
            default => is_numeric($literal) ? (float) $literal : false,
        };
    }

    /** @return string|false */
    private function parseString(): mixed
    {
        if (!$this->expect('s:')) {
            return false;
        }
        $len = $this->readUnsignedInteger();
        if (null === $len || !$this->expect(':"')) {
            return false;
        }
        $content = $this->readStringContent($len);
        if (null === $content || !$this->expect('";')) {
            return false;
        }

        return $content;
    }

    /**
     * @return array<mixed>|false
     */
    private function parseArray(int $depth): mixed
    {
        if ($depth >= $this->maxDepth) {
            return false;
        }
        if (!$this->expect('a:')) {
            return false;
        }
        $count = $this->readUnsignedInteger();
        if (null === $count || !$this->expect(':')) {
            return false;
        }
        if (!$this->expect('{')) {
            return false;
        }

        $array = [];
        for ($i = 0; $i < $count; ++$i) {
            $key = $this->parseArrayKey();
            if (false === $key && !\is_int($key) && !\is_string($key)) {
                return false;
            }
            $before = $this->pos;
            $value = $this->parseValue($depth + 1);
            if ($this->pos <= $before) {
                return false;
            }
            $array[$key] = $value;
        }
        if (!$this->expect('}')) {
            return false;
        }

        return $array;
    }

    /** @return int|string|false */
    private function parseArrayKey(): mixed
    {
        if ($this->pos >= $this->length) {
            return false;
        }
        if ('i' === $this->payload[$this->pos]) {
            return $this->parseInt();
        }
        if ('s' === $this->payload[$this->pos]) {
            return $this->parseString();
        }

        return false;
    }

    private function expect(string $literal): bool
    {
        $len = \strlen($literal);
        if ($this->pos + $len > $this->length) {
            return false;
        }
        if ($literal !== \substr($this->payload, $this->pos, $len)) {
            return false;
        }
        $this->pos += $len;

        return true;
    }

    private function readDigit(): ?int
    {
        if ($this->pos >= $this->length || !\ctype_digit($this->payload[$this->pos])) {
            return null;
        }
        $digit = (int) $this->payload[$this->pos];
        ++$this->pos;

        return $digit;
    }

    private function readUnsignedInteger(): ?int
    {
        if ($this->pos >= $this->length || !\ctype_digit($this->payload[$this->pos])) {
            return null;
        }
        $start = $this->pos;
        while ($this->pos < $this->length && \ctype_digit($this->payload[$this->pos])) {
            ++$this->pos;
        }
        $digits = \substr($this->payload, $start, $this->pos - $start);
        if ('' === $digits) {
            return null;
        }

        return (int) $digits;
    }

    private function readSignedInteger(): ?int
    {
        if ($this->pos >= $this->length) {
            return null;
        }
        $negative = false;
        if ('-' === $this->payload[$this->pos]) {
            $negative = true;
            ++$this->pos;
        }
        $unsigned = $this->readUnsignedInteger();
        if (null === $unsigned) {
            return null;
        }

        return $negative ? -$unsigned : $unsigned;
    }

    private function readStringContent(int $byteLength): ?string
    {
        if ($byteLength < 0) {
            return null;
        }
        $content = '';
        $consumed = 0;
        while ($consumed < $byteLength && $this->pos < $this->length) {
            $ch = $this->payload[$this->pos];
            if ('\\' === $ch) {
                if ($this->pos + 1 >= $this->length) {
                    return null;
                }
                $content .= $this->payload[$this->pos + 1];
                $this->pos += 2;
                $consumed += 1;

                continue;
            }
            $content .= $ch;
            ++$this->pos;
            $consumed += 1;
        }
        if ($consumed !== $byteLength) {
            return null;
        }

        return $content;
    }
}
