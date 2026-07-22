<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Zend serialize wire for SplDoublyLinkedList / SplStack / SplQueue (php-src ext/spl/spl_dllist.c; #14164).
 * __serialize/__unserialize bag shape (#22287).
 */
final class SplDllistSerializeSupport
{
    public const CLASS_DLLIST = 'spldoublylinkedlist';
    public const CLASS_STACK = 'splstack';
    public const CLASS_QUEUE = 'splqueue';

    public static function isSplDllistClass(string $lcClass): bool
    {
        return self::CLASS_DLLIST === $lcClass
            || self::CLASS_STACK === $lcClass
            || self::CLASS_QUEUE === $lcClass;
    }

    /**
     * php-src spl_dllist_object_serialize / __serialize — [flags, dllist, members].
     */
    public static function exportSerializeBag(ObjectEntry $entry): Variable
    {
        return VmJson::import([
            0 => SplDoublyLinkedListBuiltin::getIteratorMode($entry),
            1 => SplDoublyLinkedListBuiltin::exportElements($entry),
            2 => [],
        ]);
    }

    /**
     * php-src SplDoublyLinkedList::__unserialize — replace storage from bag.
     */
    public static function restoreFromSerializeBag(
        ObjectEntry $object,
        Variable $data
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
            || Variable::TYPE_ARRAY !== $slots[1]->type
            || Variable::TYPE_ARRAY !== $slots[2]->type
        ) {
            throw new \UnexpectedValueException('Incomplete or ill-typed serialization data');
        }
        $mode = $slots[0]->toInt();
        $elements = SplArrayStorage::hashTableToExportedArray($slots[1]->toArray());
        SplDoublyLinkedListBuiltin::restoreFromExported($object, $mode, $elements);
    }

    public static function encodeZendSerializeWire(ObjectEntry $entry): string
    {
        return VmSerialize::encodeIntegerKeyedPropertyBag($entry->class->name, [
            0 => SplDoublyLinkedListBuiltin::getIteratorMode($entry),
            1 => SplDoublyLinkedListBuiltin::exportElements($entry),
            2 => [],
        ]);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function restoreFromZendSerialize(
        Context $ctx,
        string $lcClass,
        array $data
    ): ?ObjectEntry {
        if (!self::isSplDllistClass($lcClass) || !isset($ctx->classes[$lcClass])) {
            return null;
        }
        if (!isset($data[1]) || !\is_array($data[1])) {
            return null;
        }
        $mode = isset($data[0]) ? (int) $data[0] : 0;
        $entry = new ObjectEntry($ctx->classes[$lcClass]);
        $entry->constructed = true;
        SplDoublyLinkedListBuiltin::restoreFromExported($entry, $mode, $data[1]);

        return $entry;
    }

    public static function registerMagicMethods(ClassEntry $entry, int $pub): void
    {
        $entry->methods['__serialize'] = new SplDllistSerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methods['__unserialize'] = new SplDllistUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methodNames['__unserialize'] = '__unserialize';
    }
}

/** php-src SplDoublyLinkedList::__serialize (#22287). */
final class SplDllistSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::__serialize()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDllistSerializeSupport::exportSerializeBag($object)
        );
    }
}

/** php-src SplDoublyLinkedList::__unserialize (#22287). */
final class SplDllistUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::__unserialize()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'SplDoublyLinkedList::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        SplDllistSerializeSupport::restoreFromSerializeBag($object, $arg);
    }
}
