<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * igbinary encode/decode (php-src ext/igbinary/igbinary.c; #6573, #21463).
 *
 * PHP-in-PHP subset: null, bool, int, float, string, array, stdClass (+ public props).
 * No resources; custom class __serialize/__sleep deferred.
 */
final class VmIgbinary
{
    public const FORMAT_VERSION = 2;

    public const TYPE_NULL = 0x00;
    public const TYPE_REF8 = 0x01;
    public const TYPE_REF16 = 0x02;
    public const TYPE_REF32 = 0x03;
    public const TYPE_BOOL_FALSE = 0x04;
    public const TYPE_BOOL_TRUE = 0x05;
    public const TYPE_LONG8P = 0x06;
    public const TYPE_LONG8N = 0x07;
    public const TYPE_LONG16P = 0x08;
    public const TYPE_LONG16N = 0x09;
    public const TYPE_LONG32P = 0x0a;
    public const TYPE_LONG32N = 0x0b;
    public const TYPE_DOUBLE = 0x0c;
    public const TYPE_STRING_EMPTY = 0x0d;
    public const TYPE_STRING8 = 0x11;
    public const TYPE_STRING16 = 0x12;
    public const TYPE_STRING32 = 0x13;
    public const TYPE_ARRAY8 = 0x14;
    public const TYPE_ARRAY16 = 0x15;
    public const TYPE_ARRAY32 = 0x16;
    public const TYPE_OBJECT8 = 0x17;
    public const TYPE_OBJECT16 = 0x18;
    public const TYPE_OBJECT32 = 0x19;
    public const TYPE_OBJECT_ID8 = 0x1a;
    public const TYPE_OBJECT_ID16 = 0x1b;
    public const TYPE_OBJECT_ID32 = 0x1c;
    public const TYPE_LONG64P = 0x20;
    public const TYPE_LONG64N = 0x21;
    public const TYPE_OBJREF8 = 0x22;
    public const TYPE_OBJREF16 = 0x23;
    public const TYPE_OBJREF32 = 0x24;

    private const INVALID_DATA = 'igbinary_unserialize(): Error at offset 0 of %d bytes';

    public static function serialize(Variable $value): string
    {
        $state = new IgbinarySerializeState();
        $state->writeHeader();
        $state->writeValue($value->resolveIndirect());

        return $state->buffer;
    }

    public static function unserialize(string $data, ?Frame $frame)
    {
        try {
            $state = new IgbinaryUnserializeState($data);

            return $state->readValue();
        } catch (IgbinaryUnpackException) {
            self::emitWarning($frame, \sprintf(self::INVALID_DATA, \strlen($data)));

            return false;
        }
    }

    private static function emitWarning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

final class IgbinaryUnpackException extends \Exception
{
}

/** @internal */
final class IgbinarySerializeState
{
    public string $buffer = '';

    /** @var array<int, int> HashTable object id => reference index */
    private array $arrayRefs = [];

    /** @var array<int, int> ObjectEntry id => reference index */
    private array $objectRefs = [];

    private int $nextRefIndex = 0;

    public function writeHeader(): void
    {
        $this->buffer .= "\0\0\0".\chr(VmIgbinary::FORMAT_VERSION);
    }

    public function writeValue(Variable $value): void
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                $this->append8(VmIgbinary::TYPE_NULL);
                break;
            case Variable::TYPE_BOOLEAN:
                $this->append8($value->toBool(null) ? VmIgbinary::TYPE_BOOL_TRUE : VmIgbinary::TYPE_BOOL_FALSE);
                break;
            case Variable::TYPE_INTEGER:
                if ($value->isVmResource()) {
                    throw new \Exception('Cannot igbinary-serialize resource');
                }
                $this->writeLong($value->toInt(null));
                break;
            case Variable::TYPE_FLOAT:
                $this->writeDouble($value->toFloat(null));
                break;
            case Variable::TYPE_STRING:
                $this->writeString($value->toString(null));
                break;
            case Variable::TYPE_ARRAY:
                $this->writeArray($value->toArray(), true);
                break;
            case Variable::TYPE_OBJECT:
                $this->writeObject($value->toObject());
                break;
            default:
                throw new \Exception('Cannot igbinary-serialize type '.$value->type);
        }
    }

    private function writeLong(int $l): void
    {
        $positive = $l >= 0;
        $k = $positive ? $l : -$l;
        if ($k <= 0xff) {
            $this->append8($positive ? VmIgbinary::TYPE_LONG8P : VmIgbinary::TYPE_LONG8N);
            $this->append8($k & 0xff);
        } elseif ($k <= 0xffff) {
            $this->append8($positive ? VmIgbinary::TYPE_LONG16P : VmIgbinary::TYPE_LONG16N);
            $this->append16($k);
        } elseif ($k <= 0xffffffff) {
            $this->append8($positive ? VmIgbinary::TYPE_LONG32P : VmIgbinary::TYPE_LONG32N);
            $this->append32($k);
        } else {
            $this->append8($positive ? VmIgbinary::TYPE_LONG64P : VmIgbinary::TYPE_LONG64N);
            $this->append64($k);
        }
    }

    private function writeDouble(float $d): void
    {
        $this->append8(VmIgbinary::TYPE_DOUBLE);
        $bits = \unpack('J', \pack('E', $d));
        if (false === $bits) {
            throw new \Exception('igbinary double pack failed');
        }
        $this->append64((int) $bits[1]);
    }

    private function writeString(string $s): void
    {
        $len = \strlen($s);
        if (0 === $len) {
            $this->append8(VmIgbinary::TYPE_STRING_EMPTY);

            return;
        }
        if ($len <= 0xff) {
            $this->append8(VmIgbinary::TYPE_STRING8);
            $this->append8($len);
        } elseif ($len <= 0xffff) {
            $this->append8(VmIgbinary::TYPE_STRING16);
            $this->append16($len);
        } else {
            $this->append8(VmIgbinary::TYPE_STRING32);
            $this->append32($len);
        }
        $this->buffer .= $s;
    }

    /**
     * Serialize object as class name + property array (php-src igbinary_serialize_object; #21463).
     *
     * MVP: stdClass / plain public props only (no __serialize / __sleep / Serializable).
     */
    private function writeObject(ObjectEntry $object): void
    {
        $id = $object->id;
        if (isset($this->objectRefs[$id])) {
            $this->writeObjRef($this->objectRefs[$id]);

            return;
        }
        $this->objectRefs[$id] = $this->nextRefIndex++;

        $className = $object->class->name;
        $this->writeObjectName($className);

        $pairs = [];
        foreach ($object->propertiesWithNames() as $name => $propVar) {
            $pairs[] = [(string) $name, $propVar->resolveIndirect()];
        }
        $n = \count($pairs);
        if ($n <= 0xff) {
            $this->append8(VmIgbinary::TYPE_ARRAY8);
            $this->append8($n);
        } elseif ($n <= 0xffff) {
            $this->append8(VmIgbinary::TYPE_ARRAY16);
            $this->append16($n);
        } else {
            $this->append8(VmIgbinary::TYPE_ARRAY32);
            $this->append32($n);
        }
        foreach ($pairs as [$key, $valVar]) {
            $this->writeString($key);
            $this->writeValue($valVar);
        }
    }

    private function writeObjectName(string $className): void
    {
        $len = \strlen($className);
        if ($len <= 0xff) {
            $this->append8(VmIgbinary::TYPE_OBJECT8);
            $this->append8($len);
        } elseif ($len <= 0xffff) {
            $this->append8(VmIgbinary::TYPE_OBJECT16);
            $this->append16($len);
        } else {
            $this->append8(VmIgbinary::TYPE_OBJECT32);
            $this->append32($len);
        }
        $this->buffer .= $className;
    }

    private function writeObjRef(int $index): void
    {
        if ($index <= 0xff) {
            $this->append8(VmIgbinary::TYPE_OBJREF8);
            $this->append8($index);
        } elseif ($index <= 0xffff) {
            $this->append8(VmIgbinary::TYPE_OBJREF16);
            $this->append16($index);
        } else {
            $this->append8(VmIgbinary::TYPE_OBJREF32);
            $this->append32($index);
        }
    }

    private function writeArray(HashTable $ht, bool $trackRef): void
    {
        $id = \spl_object_id($ht);
        if ($trackRef && isset($this->arrayRefs[$id])) {
            $this->writeRef($this->arrayRefs[$id]);

            return;
        }
        if ($trackRef) {
            $this->arrayRefs[$id] = $this->nextRefIndex++;
        }

        $pairs = self::arrayPairs($ht);
        $n = \count($pairs);
        if ($n <= 0xff) {
            $this->append8(VmIgbinary::TYPE_ARRAY8);
            $this->append8($n);
        } elseif ($n <= 0xffff) {
            $this->append8(VmIgbinary::TYPE_ARRAY16);
            $this->append16($n);
        } else {
            $this->append8(VmIgbinary::TYPE_ARRAY32);
            $this->append32($n);
        }

        foreach ($pairs as [$key, $valVar]) {
            if (\is_int($key)) {
                $this->writeLong($key);
            } else {
                $this->writeString($key);
            }
            $this->writeValue($valVar);
        }
    }

    private function writeRef(int $index): void
    {
        if ($index <= 0xff) {
            $this->append8(VmIgbinary::TYPE_REF8);
            $this->append8($index);
        } elseif ($index <= 0xffff) {
            $this->append8(VmIgbinary::TYPE_REF16);
            $this->append16($index);
        } else {
            $this->append8(VmIgbinary::TYPE_REF32);
            $this->append32($index);
        }
    }

    /**
     * @return list{array{0: int|string, 1: Variable}}>
     */
    private static function arrayPairs(HashTable $ht): array
    {
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $key->type) {
                $pairs[] = [$key->toInt(null), $valVar];
            } else {
                $pairs[] = [$key->toString(null), $valVar];
            }
        }

        return $pairs;
    }

    private function append8(int $byte): void
    {
        $this->buffer .= \chr($byte & 0xff);
    }

    private function append16(int $value): void
    {
        $this->buffer .= \pack('n', $value & 0xffff);
    }

    private function append32(int $value): void
    {
        $this->buffer .= \pack('N', $value & 0xffffffff);
    }

    private function append64(int $value): void
    {
        $hi = ($value >> 32) & 0xffffffff;
        $lo = $value & 0xffffffff;
        $this->buffer .= \pack('N', $hi).\pack('N', $lo);
    }
}

/** @internal */
final class IgbinaryUnserializeState
{
    private int $offset = 0;

    /** @var list<mixed> */
    private array $refs = [];

    public function __construct(private readonly string $data)
    {
        if (\strlen($data) < 5) {
            throw new IgbinaryUnpackException();
        }
        $version = \unpack('N', \substr($data, 0, 4));
        if (false === $version || (1 !== $version[1] && 2 !== $version[1])) {
            throw new IgbinaryUnpackException();
        }
        $this->offset = 4;
    }

    public function readValue()
    {
        $type = $this->read8();

        return match ($type) {
            VmIgbinary::TYPE_NULL => null,
            VmIgbinary::TYPE_BOOL_FALSE => false,
            VmIgbinary::TYPE_BOOL_TRUE => true,
            VmIgbinary::TYPE_LONG8P => $this->read8(),
            VmIgbinary::TYPE_LONG8N => -$this->read8(),
            VmIgbinary::TYPE_LONG16P => $this->read16(),
            VmIgbinary::TYPE_LONG16N => -$this->read16(),
            VmIgbinary::TYPE_LONG32P => $this->read32(),
            VmIgbinary::TYPE_LONG32N => -$this->read32(),
            VmIgbinary::TYPE_LONG64P => $this->read64Signed(),
            VmIgbinary::TYPE_LONG64N => -$this->read64Signed(),
            VmIgbinary::TYPE_DOUBLE => $this->readDouble(),
            VmIgbinary::TYPE_STRING_EMPTY => '',
            VmIgbinary::TYPE_STRING8 => $this->readString($this->read8()),
            VmIgbinary::TYPE_STRING16 => $this->readString($this->read16()),
            VmIgbinary::TYPE_STRING32 => $this->readString($this->read32()),
            VmIgbinary::TYPE_ARRAY8 => $this->readArray($this->read8(), true),
            VmIgbinary::TYPE_ARRAY16 => $this->readArray($this->read16(), true),
            VmIgbinary::TYPE_ARRAY32 => $this->readArray($this->read32(), true),
            VmIgbinary::TYPE_OBJECT8 => $this->readObject($this->readString($this->read8())),
            VmIgbinary::TYPE_OBJECT16 => $this->readObject($this->readString($this->read16())),
            VmIgbinary::TYPE_OBJECT32 => $this->readObject($this->readString($this->read32())),
            VmIgbinary::TYPE_OBJECT_ID8,
            VmIgbinary::TYPE_OBJECT_ID16,
            VmIgbinary::TYPE_OBJECT_ID32 => throw new IgbinaryUnpackException(),
            VmIgbinary::TYPE_REF8 => $this->refs[$this->read8()] ?? throw new IgbinaryUnpackException(),
            VmIgbinary::TYPE_REF16 => $this->refs[$this->read16()] ?? throw new IgbinaryUnpackException(),
            VmIgbinary::TYPE_REF32 => $this->refs[$this->read32()] ?? throw new IgbinaryUnpackException(),
            VmIgbinary::TYPE_OBJREF8 => $this->refs[$this->read8()] ?? throw new IgbinaryUnpackException(),
            VmIgbinary::TYPE_OBJREF16 => $this->refs[$this->read16()] ?? throw new IgbinaryUnpackException(),
            VmIgbinary::TYPE_OBJREF32 => $this->refs[$this->read32()] ?? throw new IgbinaryUnpackException(),
            default => throw new IgbinaryUnpackException(),
        };
    }

    /**
     * Decode object8/16/32 + property array into a host stdClass (imported to VM via VmJson).
     *
     * MVP (#21463): only `stdClass` class names; other classes fail unpack.
     */
    private function readObject(string $className): \stdClass
    {
        if ('stdClass' !== $className) {
            throw new IgbinaryUnpackException();
        }
        $obj = new \stdClass();
        $this->refs[] = $obj;
        $type = $this->read8();
        $count = match ($type) {
            VmIgbinary::TYPE_ARRAY8 => $this->read8(),
            VmIgbinary::TYPE_ARRAY16 => $this->read16(),
            VmIgbinary::TYPE_ARRAY32 => $this->read32(),
            default => throw new IgbinaryUnpackException(),
        };
        for ($i = 0; $i < $count; ++$i) {
            $key = $this->readValue();
            $val = $this->readValue();
            if (!\is_string($key) && !\is_int($key)) {
                throw new IgbinaryUnpackException();
            }
            $obj->{(string) $key} = $val;
        }

        return $obj;
    }

    private function readArray(int $count, bool $registerRef): array
    {
        $result = [];
        if ($registerRef) {
            $this->refs[] = &$result;
        }
        for ($i = 0; $i < $count; ++$i) {
            $key = $this->readValue();
            $val = $this->readValue();
            if (!\is_int($key) && !\is_string($key)) {
                throw new IgbinaryUnpackException();
            }
            $result[$key] = $val;
        }

        return $result;
    }

    private function readDouble(): float
    {
        $bits = $this->read64();
        $packed = \pack('J', $bits);
        $float = \unpack('E', $packed);

        return false === $float ? 0.0 : (float) $float[1];
    }

    private function readString(int $len): string
    {
        if ($len < 0 || $this->offset + $len > \strlen($this->data)) {
            throw new IgbinaryUnpackException();
        }
        $s = \substr($this->data, $this->offset, $len);
        $this->offset += $len;

        return $s;
    }

    private function read8(): int
    {
        if ($this->offset >= \strlen($this->data)) {
            throw new IgbinaryUnpackException();
        }

        return \ord($this->data[$this->offset++]);
    }

    private function read16(): int
    {
        if ($this->offset + 2 > \strlen($this->data)) {
            throw new IgbinaryUnpackException();
        }
        $v = \unpack('n', \substr($this->data, $this->offset, 2));
        $this->offset += 2;
        if (false === $v) {
            throw new IgbinaryUnpackException();
        }

        return $v[1];
    }

    private function read32(): int
    {
        if ($this->offset + 4 > \strlen($this->data)) {
            throw new IgbinaryUnpackException();
        }
        $v = \unpack('N', \substr($this->data, $this->offset, 4));
        $this->offset += 4;
        if (false === $v) {
            throw new IgbinaryUnpackException();
        }

        return $v[1];
    }

    private function read64(): int
    {
        if ($this->offset + 8 > \strlen($this->data)) {
            throw new IgbinaryUnpackException();
        }
        $hi = \unpack('N', \substr($this->data, $this->offset, 4));
        $lo = \unpack('N', \substr($this->data, $this->offset + 4, 4));
        $this->offset += 8;
        if (false === $hi || false === $lo) {
            throw new IgbinaryUnpackException();
        }

        return ($hi[1] << 32) | $lo[1];
    }

    private function read64Signed(): int
    {
        $u = $this->read64();
        if ($u >= 0x8000000000000000) {
            return (int) ($u - 0x10000000000000000);
        }

        return (int) $u;
    }
}
