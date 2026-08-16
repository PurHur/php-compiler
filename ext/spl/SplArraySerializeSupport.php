<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\ext\standard\VmUnserializeFormat;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Zend serialize wire for SPL ArrayObject / ArrayIterator (php-src ext/spl/spl_array.c; #10711).
 * __serialize/__unserialize bag shape (#22269).
 */
final class SplArraySerializeSupport
{
    public const CLASS_ARRAYOBJECT = 'arrayobject';
    public const CLASS_ARRAYITERATOR = 'arrayiterator';

    public const CLASS_RECURSIVEARRAYITERATOR = 'recursivearrayiterator';

    public static function isSplArrayClass(string $lcClass): bool
    {
        return self::CLASS_ARRAYOBJECT === $lcClass
            || self::CLASS_ARRAYITERATOR === $lcClass
            || self::CLASS_RECURSIVEARRAYITERATOR === $lcClass;
    }

    /**
     * php-src ArrayObject/ArrayIterator::__serialize — [flags, storage, members, iteratorClass].
     */
    public static function exportSerializeBag(ObjectEntry $entry): Variable
    {
        if (!SplArrayStorage::hasState($entry)) {
            return VmJson::import([0 => 0, 1 => [], 2 => [], 3 => null]);
        }
        $state = SplArrayStorage::state($entry);

        return VmJson::import([
            0 => $state['flags'],
            1 => SplArrayStorage::hashTableToExportedArray($state['table']),
            2 => $state['propList'],
            3 => $state['iteratorClass'],
        ]);
    }

    /**
     * php-src ArrayObject/ArrayIterator::__unserialize — replace storage from bag.
     */
    public static function restoreFromSerializeBag(
        Context $ctx,
        ObjectEntry $object,
        Variable $data,
        string $displayName
    ): void {
        $slots = [];
        foreach ($data->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                continue;
            }
            $slots[$key->toInt()] = $valueVar->resolveIndirect();
        }
        if (
            !isset($slots[0], $slots[1], $slots[2])
            || Variable::TYPE_INTEGER !== $slots[0]->type
            || Variable::TYPE_ARRAY !== $slots[2]->type
        ) {
            throw new \UnexpectedValueException('Incomplete or ill-typed serialization data');
        }
        $iteratorClass = null;
        if (isset($slots[3])) {
            $iter = $slots[3];
            if (Variable::TYPE_NULL === $iter->type) {
                $iteratorClass = null;
            } elseif (Variable::TYPE_STRING === $iter->type) {
                $iteratorClass = $iter->toString();
            } else {
                throw new \UnexpectedValueException('Incomplete or ill-typed serialization data');
            }
        }
        $storage = $slots[1];
        if (Variable::TYPE_ARRAY !== $storage->type && Variable::TYPE_OBJECT !== $storage->type) {
            throw new \InvalidArgumentException('Passed variable is not an array or object');
        }
        $flags = $slots[0]->toInt();
        $storageExported = Variable::TYPE_ARRAY === $storage->type
            ? SplArrayStorage::hashTableToExportedArray($storage->toArray())
            : [];
        $propList = SplArrayStorage::hashTableToExportedArray($slots[2]->toArray());
        SplArrayStorage::restoreFromExported(
            $ctx,
            $object,
            $flags,
            $storageExported,
            $propList,
            $iteratorClass
        );
        unset($displayName);
    }

    public static function encodeZendSerializeWire(ObjectEntry $entry): string
    {
        if (!SplArrayStorage::hasState($entry)) {
            return VmSerialize::encodeIntegerKeyedPropertyBag($entry->class->name, [
                0 => 0,
                1 => [],
                2 => [],
                3 => null,
            ]);
        }
        $state = SplArrayStorage::state($entry);

        return VmSerialize::encodeIntegerKeyedPropertyBag($entry->class->name, [
            0 => $state['flags'],
            1 => SplArrayStorage::hashTableToExportedArray($state['table']),
            2 => $state['propList'],
            3 => $state['iteratorClass'],
        ]);
    }

    /**
     * php-src ArrayObject/ArrayIterator::serialize — custom x:/m: wire (not O:).
     * SPL_ARRAY_CLONE_MASK / SPL_ARRAY_IS_SELF from ext/spl/spl_array.h.
     */
    private const LEGACY_CLONE_MASK = 0x0100FFFF;

    private const LEGACY_IS_SELF = 0x01000000;

    public static function encodeLegacySerializeWire(ObjectEntry $entry): string
    {
        $flags = 0;
        $storage = [];
        $propList = [];
        if (SplArrayStorage::hasState($entry)) {
            $state = SplArrayStorage::state($entry);
            $flags = $state['flags'] & self::LEGACY_CLONE_MASK;
            $storage = SplArrayStorage::hashTableToExportedArray($state['table']);
            $propList = $state['propList'];
        }
        $buf = 'x:'.VmSerialize::serializeExported($flags);
        if (0 === ($flags & self::LEGACY_IS_SELF)) {
            $buf .= VmSerialize::serializeExported($storage).';';
        }
        $buf .= 'm:'.VmSerialize::serializeExported($propList);

        return $buf;
    }

    /**
     * php-src zim_ArrayObject_unserialize — mutate $this from x:/m: wire (#31595).
     */
    public static function restoreFromLegacySerializeWire(
        Context $ctx,
        ObjectEntry $object,
        string $buf
    ): void {
        $bufLen = \strlen($buf);
        if (0 === $bufLen) {
            return;
        }
        $p = 0;
        if ($buf[$p] !== 'x') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;
        if ($p >= $bufLen || $buf[$p] !== ':') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;

        $flagsDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx);
        if (false === $flagsDecoded || Variable::TYPE_INTEGER !== $flagsDecoded[0]->type) {
            throw self::legacyUnserializeErrorAt(
                VmUnserializeFormat::lastErrorOffset() ?? $p,
                $bufLen
            );
        }
        [$flagsVar, $p] = $flagsDecoded;
        // php-src: --p then require ';'; decodeOneFrom already consumed the terminator.
        if ($p < 1 || $buf[$p - 1] !== ';') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        $flags = $flagsVar->toInt() & self::LEGACY_CLONE_MASK;

        $storageExported = [];
        if (0 !== ($flags & self::LEGACY_IS_SELF)) {
            // php-src: undef array storage when IS_SELF.
            $storageExported = [];
        } else {
            if ($p >= $bufLen) {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            $type = $buf[$p];
            if ('a' !== $type && 'O' !== $type && 'C' !== $type && 'r' !== $type) {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            $arrayDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx);
            if (false === $arrayDecoded) {
                throw self::legacyUnserializeErrorAt(
                    VmUnserializeFormat::lastErrorOffset() ?? $p,
                    $bufLen
                );
            }
            [$arrayVar, $p] = $arrayDecoded;
            if (Variable::TYPE_ARRAY === $arrayVar->type) {
                $storageExported = SplArrayStorage::hashTableToExportedArray($arrayVar->toArray());
            } elseif (Variable::TYPE_OBJECT === $arrayVar->type) {
                // Object storage via spl_array_set_array — export public props when possible.
                $storageExported = [];
            } else {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            if ($p >= $bufLen || $buf[$p] !== ';') {
                throw self::legacyUnserializeErrorAt($p, $bufLen);
            }
            ++$p;
        }

        if ($p >= $bufLen || $buf[$p] !== 'm') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;
        if ($p >= $bufLen || $buf[$p] !== ':') {
            throw self::legacyUnserializeErrorAt($p, $bufLen);
        }
        ++$p;

        $membersDecoded = VmUnserializeFormat::decodeOneFrom($buf, $p, null, $ctx);
        if (false === $membersDecoded || Variable::TYPE_ARRAY !== $membersDecoded[0]->type) {
            throw self::legacyUnserializeErrorAt(
                VmUnserializeFormat::lastErrorOffset() ?? $p,
                $bufLen
            );
        }
        [$membersVar, $pAfter] = $membersDecoded;
        unset($pAfter);
        $propList = SplArrayStorage::hashTableToExportedArray($membersVar->toArray());
        $iteratorClass = SplArrayStorage::hasState($object)
            ? SplArrayStorage::state($object)['iteratorClass']
            : null;
        SplArrayStorage::restoreFromExported(
            $ctx,
            $object,
            $flags,
            $storageExported,
            $propList,
            $iteratorClass
        );
    }

    private static function legacyUnserializeErrorAt(int $offset, int $bufLen): \UnexpectedValueException
    {
        return new \UnexpectedValueException(
            \sprintf('Error at offset %d of %d bytes', $offset, $bufLen)
        );
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function restoreFromZendSerialize(
        Context $ctx,
        string $lcClass,
        array $data
    ): ?ObjectEntry {
        if (!self::isSplArrayClass($lcClass) || !isset($ctx->classes[$lcClass])) {
            return null;
        }
        if (!isset($data[1]) || !\is_array($data[1])) {
            return null;
        }
        $flags = isset($data[0]) ? (int) $data[0] : 0;
        $propList = isset($data[2]) && \is_array($data[2]) ? $data[2] : [];
        $iteratorClass = $data[3] ?? null;
        if (null !== $iteratorClass && !\is_string($iteratorClass)) {
            $iteratorClass = null;
        }
        $entry = new ObjectEntry($ctx->classes[$lcClass]);
        $entry->constructed = true;
        SplArrayStorage::restoreFromExported(
            $ctx,
            $entry,
            $flags,
            $data[1],
            $propList,
            $iteratorClass
        );

        return $entry;
    }

    public static function registerMagicMethods(
        \PHPCompiler\VM\ClassEntry $entry,
        string $ownerLc,
        string $displayName,
        int $pub
    ): void {
        $entry->methods['__serialize'] = new SplArraySerialize($ownerLc, $displayName);
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methods['__unserialize'] = new SplArrayUnserialize($ownerLc, $displayName);
        $entry->methodVisibility['__unserialize'] = $pub;
        // php-src spl_array.stub.php — __unserialize(array $data); needed when subclasses
        // inherit methods without redeclaring (#25840 inherit copies metadata).
        $entry->methodParameterMetadata['__serialize'] = [];
        $entry->methodParameterMetadata['__unserialize'] = [
            new ParameterMetadata('data', [], false, false, false, false, 'array', null),
        ];
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methodNames['__unserialize'] = '__unserialize';
    }
}

/** php-src ArrayObject/ArrayIterator::__serialize (#22269). */
final class SplArraySerialize extends VmClassMethod
{
    public function __construct(
        private readonly string $ownerLc,
        private readonly string $displayName
    ) {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            $this->ownerLc,
            $this->displayName.'::__serialize()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplArraySerializeSupport::exportSerializeBag($object)
        );
    }
}

/** php-src ArrayObject/ArrayIterator::__unserialize (#22269). */
final class SplArrayUnserialize extends VmClassMethod
{
    public function __construct(
        private readonly string $ownerLc,
        private readonly string $displayName
    ) {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            $this->ownerLc,
            $this->displayName.'::__unserialize()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                $this->displayName.'::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                $this->displayName.'::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException($this->displayName.'::__unserialize() without VM context');
        }
        SplArraySerializeSupport::restoreFromSerializeBag(
            $frame->vmContext,
            $object,
            $arg,
            $this->displayName
        );
    }
}
