<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\EnumCasePropertyJitHelper;
use PHPLLVM;
use PHPLLVM\Value;

/**
 * LLVM lowering for enum object string-cast errors — messages via EnumCasePropertyJitHelper PHP (#9938).
 */
final class ObjectEnumStringCastLlvm
{
    /**
     * Enum case __value__ entries used as array keys must throw Error (ext/standard/array.c #5538).
     */
    public static function emitEnumCaseValueEntryStringCastError(Object_ $object, Context $context, Value $valueEntry): void
    {
        $enumEntries = $object->knownEnumClassIdsToNames();
        if ([] === $enumEntries) {
            ErrorRaise::emitRaise(
                $context,
                EnumCasePropertyJitHelper::enumStringCastErrorMessage('enum')
            );

            return;
        }

        ErrorRaise::ensureLinked($context);
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            ErrorRaise::emitRaise(
                $context,
                EnumCasePropertyJitHelper::enumStringCastErrorMessage('enum')
            );

            return;
        }
        $classId = $context->builder->load(
            $context->builder->structGep($valueEntry, $enumMap['class_id'])
        );
        self::emitEnumClassIdStringCastErrorChain($context, $classId, $enumEntries, 'enum_val_str_cast');
    }

    /**
     * Non-enum object __value__ entries used as array keys must throw Error (ext/standard/array.c #4161).
     */
    public static function emitObjectValueEntryStringCastError(Object_ $object, Context $context, Value $valueEntry): void
    {
        ErrorRaise::ensureLinked($context);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valueEntry
        );
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $nonEnumClasses = [];
        foreach ($object->classIdToNameEntries() as $id => $name) {
            if (!$object->isRegisteredEnumLc(strtolower(ltrim($name, '\\')))) {
                $nonEnumClasses[(int) $id] = $name;
            }
        }
        if ([] === $nonEnumClasses) {
            ErrorRaise::emitRaise($context, 'Object of class stdClass could not be converted to string');

            return;
        }
        self::emitEnumClassIdStringCastErrorChain($context, $classId, $nonEnumClasses, 'obj_val_str_cast');
    }

    /**
     * Zend string cast on enum case objects must throw Error (zend_enum.c, #4819).
     */
    public static function emitEnumObjectStringErrorIfMatches(Object_ $object, Context $context, Value $objPtr): void
    {
        $enumEntries = $object->knownEnumClassIdsToNames();
        if ([] === $enumEntries) {
            return;
        }

        ErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        self::emitEnumClassIdStringCastErrorChain($context, $classId, $enumEntries, 'enum_str_cast');
    }

    /**
     * @param array<int, string> $enumEntries
     */
    public static function emitEnumClassIdStringCastErrorChain(
        Context $context,
        Value $classId,
        array $enumEntries,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $ids = array_keys($enumEntries);
        $lastIdx = count($ids) - 1;
        foreach ($ids as $idx => $id) {
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $doneBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    PHPLLVM\Builder::INT_EQ,
                    $classId,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            ErrorRaise::emitRaise(
                $context,
                EnumCasePropertyJitHelper::enumStringCastErrorMessage($enumEntries[$id])
            );
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($nextBlock);
        }
        $context->builder->positionAtEnd($doneBlock);
    }
}
