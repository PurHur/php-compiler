<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Native unserialize() scalar/array decoder — pairs with {@see VmSerializeFormat} (issue #8191).
 *
 * php-src: ext/standard/var_unserializer.c
 */
final class VmUnserializeFormat
{
    private const DEFAULT_MAX_DEPTH = 4096;

    private static ?int $lastErrorOffset = null;

    private static ?int $lastPayloadLength = null;

    private int $pos = 0;

    private readonly int $length;

    /** @var list<VmUnserializeCell|null> php-src var_hash — 1-indexed */
    private array $refTable = [null];

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
        $root = self::parseRootCell($payload, $options);
        if (false === $root) {
            return false;
        }

        return self::materializeCell($root);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public static function decodeToVariable(string $payload, ?array $options = null): Variable|false
    {
        $root = self::parseRootCell($payload, $options);
        if (false === $root) {
            return false;
        }

        $canonical = [];
        $slotForCell = [];

        return self::cellToVariable($root, $canonical, $slotForCell);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function parseRootCell(string $payload, ?array $options = null): VmUnserializeCell|false
    {
        self::$lastErrorOffset = null;
        self::$lastPayloadLength = null;
        if ('' === $payload) {
            return false;
        }
        $maxDepth = self::DEFAULT_MAX_DEPTH;
        if (null !== $options && \array_key_exists('max_depth', $options)) {
            $maxDepth = (int) $options['max_depth'];
        }

        $parser = new self($payload, $maxDepth);
        $value = $parser->parseValue(0);
        if (false === $value) {
            return false;
        }
        if ($parser->pos !== $parser->length) {
            return $parser->failCell();
        }

        return $value;
    }

    public static function lastErrorOffset(): ?int
    {
        return self::$lastErrorOffset;
    }

    public static function lastPayloadLength(): ?int
    {
        return self::$lastPayloadLength;
    }

    /** @return false */
    private function fail(): bool
    {
        self::$lastErrorOffset = $this->pos;
        self::$lastPayloadLength = $this->length;

        return false;
    }

    private function failCell(): false
    {
        $this->fail();

        return false;
    }

    private function pushRef(VmUnserializeCell $cell): void
    {
        $this->refTable[] = $cell;
    }

    private function parseValue(int $depth): VmUnserializeCell|false
    {
        if ($this->pos >= $this->length) {
            return $this->failCell();
        }

        $type = $this->payload[$this->pos];
        return match ($type) {
            'N' => $this->parseNull(),
            'b' => $this->parseBool(),
            'i' => $this->parseInt(),
            'd' => $this->parseDouble(),
            's' => $this->parseString(),
            'a' => $this->parseArray($depth),
            'R' => $this->parseReference(),
            default => $this->failCell(),
        };
    }

    /** php-src var_push_deref — R: index; (#12080) */
    private function parseReference(): VmUnserializeCell|false
    {
        if (!$this->expect('R:')) {
            return $this->failCell();
        }
        $index = $this->readUnsignedInteger();
        if (null === $index || !$this->expect(';')) {
            return $this->failCell();
        }
        if (!isset($this->refTable[$index])) {
            return $this->failCell();
        }
        $cell = $this->refTable[$index];
        $this->pushRef($cell);

        return $cell;
    }

    private function parseNull(): VmUnserializeCell|false
    {
        if (!$this->expect('N;')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = null;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseBool(): VmUnserializeCell|false
    {
        if (!$this->expect('b:')) {
            return $this->failCell();
        }
        $digit = $this->readDigit();
        if (null === $digit || !$this->expect(';')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = 1 === $digit;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseInt(): VmUnserializeCell|false
    {
        if (!$this->expect('i:')) {
            return $this->failCell();
        }
        $number = $this->readSignedInteger();
        if (null === $number || !$this->expect(';')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = $number;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseDouble(): VmUnserializeCell|false
    {
        if (!$this->expect('d:')) {
            return $this->failCell();
        }
        $start = $this->pos;
        while ($this->pos < $this->length && ';' !== $this->payload[$this->pos]) {
            ++$this->pos;
        }
        if ($this->pos >= $this->length) {
            return $this->failCell();
        }
        $literal = \substr($this->payload, $start, $this->pos - $start);
        ++$this->pos;

        $decoded = match ($literal) {
            'NAN' => \NAN,
            'INF' => \INF,
            '-INF' => -\INF,
            default => is_numeric($literal) ? (float) $literal : false,
        };
        if (false === $decoded) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = $decoded;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseString(): VmUnserializeCell|false
    {
        if (!$this->expect('s:')) {
            return $this->failCell();
        }
        $len = $this->readUnsignedInteger();
        if (null === $len || !$this->expect(':"')) {
            return $this->failCell();
        }
        $content = $this->readStringContent($len);
        if (null === $content || !$this->expect('";')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = $content;
        $this->pushRef($cell);

        return $cell;
    }

    /**
     * @return VmUnserializeCell|false
     */
    private function parseArray(int $depth): VmUnserializeCell|false
    {
        if ($depth >= $this->maxDepth) {
            return $this->failCell();
        }
        if (!$this->expect('a:')) {
            return $this->failCell();
        }
        $count = $this->readUnsignedInteger();
        if (null === $count || !$this->expect(':')) {
            return $this->failCell();
        }
        if (!$this->expect('{')) {
            return $this->failCell();
        }

        /** @var array<int|string, VmUnserializeCell> $elements */
        $elements = [];
        for ($i = 0; $i < $count; ++$i) {
            $keyCell = $this->parseArrayKey();
            if (false === $keyCell) {
                return $this->failCell();
            }
            $before = $this->pos;
            $valueCell = $this->parseValue($depth + 1);
            if (false === $valueCell || $this->pos <= $before) {
                return $this->failCell();
            }
            $elements[self::cellScalar($keyCell)] = $valueCell;
        }
        if (!$this->expect('}')) {
            return $this->failCell();
        }

        $cell = new VmUnserializeCell();
        $cell->value = $elements;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseArrayKey(): VmUnserializeCell|false
    {
        if ($this->pos >= $this->length) {
            return $this->failCell();
        }
        if ('i' === $this->payload[$this->pos]) {
            return $this->parseInt();
        }
        if ('s' === $this->payload[$this->pos]) {
            return $this->parseString();
        }

        return $this->failCell();
    }

    private static function cellScalar(VmUnserializeCell $cell): int|string
    {
        if (!\is_int($cell->value) && !\is_string($cell->value)) {
            throw new \LogicException('unserialize() array key must be int or string');
        }

        return $cell->value;
    }

    /**
     * @param array<int, Variable> $canonical
     * @param array<int, Variable> $slotForCell
     */
    private static function cellToVariable(VmUnserializeCell $cell, array &$canonical, array &$slotForCell): Variable
    {
        $id = spl_object_id($cell);
        if (isset($slotForCell[$id])) {
            return $slotForCell[$id];
        }
        if (isset($canonical[$id])) {
            $alias = new Variable();
            $alias->indirect($canonical[$id]);
            $slotForCell[$id] = $alias;

            return $alias;
        }

        if (\is_array($cell->value)) {
            $var = new Variable();
            $ht = new HashTable();
            $isList = self::isListCellMap($cell->value);
            foreach ($cell->value as $key => $child) {
                \assert($child instanceof VmUnserializeCell);
                $slot = self::cellToVariable($child, $canonical, $slotForCell);
                if ($isList) {
                    if ($slot->isIndirect()) {
                        $ht->updateIndirectIndex((int) $key, $slot);
                    } else {
                        $ht->addIndex((int) $key, $slot);
                    }
                } else {
                    if ($slot->isIndirect()) {
                        $ht->updateIndirect((string) $key, $slot);
                    } else {
                        $ht->add((string) $key, $slot);
                    }
                }
            }
            $var->array($ht);
            $canonical[$id] = $var;
            $slotForCell[$id] = $var;

            return $var;
        }

        $storage = new Variable();
        if (null === $cell->value) {
            $storage->null();
        } elseif (\is_bool($cell->value)) {
            $storage->bool($cell->value);
        } elseif (\is_int($cell->value)) {
            $storage->int($cell->value);
        } elseif (\is_float($cell->value)) {
            $storage->float($cell->value);
        } elseif (\is_string($cell->value)) {
            $storage->string($cell->value);
        } else {
            throw new \LogicException('unserialize() result type not supported in this compiler build');
        }
        $canonical[$id] = $storage;
        $wrapper = new Variable();
        $wrapper->indirect($storage);
        $slotForCell[$id] = $wrapper;

        return $wrapper;
    }

    /**
     * @param array<int|string, VmUnserializeCell> $cells
     */
    private static function isListCellMap(array $cells): bool
    {
        $i = 0;
        foreach ($cells as $key => $_) {
            if ($key !== $i) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $seen
     */
    private static function &materializeCell(VmUnserializeCell $cell, array &$seen = []): mixed
    {
        $id = spl_object_id($cell);
        if (isset($seen[$id])) {
            return $seen[$id];
        }

        if (\is_array($cell->value)) {
            $array = [];
            $seen[$id] = &$array;
            foreach ($cell->value as $key => $child) {
                \assert($child instanceof VmUnserializeCell);
                $array[$key] = &self::materializeCell($child, $seen);
            }

            return $array;
        }

        $seen[$id] = $cell->value;

        return $seen[$id];
    }

    private function expect(string $literal): bool
    {
        $len = \strlen($literal);
        if ($this->pos + $len > $this->length) {
            return $this->fail();
        }
        if ($literal !== \substr($this->payload, $this->pos, $len)) {
            return $this->fail();
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

/** Identity cell for php-src var_hash / R: markers (var_unserializer.re, #12080). */
final class VmUnserializeCell
{
    /** @var array<int|string, VmUnserializeCell>|bool|float|int|null|string */
    public mixed $value = null;
}
