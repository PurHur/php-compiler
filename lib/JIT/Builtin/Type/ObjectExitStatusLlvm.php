<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ScriptExit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM;
use PHPLLVM\Value;

/**
 * LLVM lowering for exit()/die() object status — ExitStatus enum vs other enums (#9938).
 */
final class ObjectExitStatusLlvm
{
    public static function emitExitStatusFromEnumCaseObject(Object_ $object, Context $context, Value $objPtr): void
    {
        $exitStatusId = $object->exitStatusEnumClassId();
        if (null === $exitStatusId) {
            ScriptExit::emitStatusTypeErrorAndAbort($context, 'object');

            return;
        }
        $status = ObjectEnumCasePropertyLlvm::enumCaseBackingLong($object, $context, $objPtr);
        ScriptExit::emitLibcExitWithStatus($context, $status);
    }

    public static function emitExitStatusObjectGuard(Object_ $object, Context $context, Value $objPtr): void
    {
        $exitStatusId = $object->exitStatusEnumClassId();
        $enumEntries = self::nonExitStatusEnumEntries($object);

        \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $typeErrorBlock = BasicBlockHelper::append($context, 'exit_status_obj_type_error');

        if (null !== $exitStatusId) {
            $exitStatusBlock = BasicBlockHelper::append($context, 'exit_status_obj_exitstatus');
            $afterExitStatus = [] === $enumEntries
                ? $typeErrorBlock
                : BasicBlockHelper::append($context, 'exit_status_obj_after_exitstatus');
            $context->builder->branchIf(
                $context->builder->icmp(
                    PHPLLVM\Builder::INT_EQ,
                    $classId,
                    $i64->constInt($exitStatusId, false)
                ),
                $exitStatusBlock,
                $afterExitStatus
            );
            $context->builder->positionAtEnd($exitStatusBlock);
            self::emitExitStatusFromEnumCaseObject($object, $context, $objPtr);

            if ([] === $enumEntries) {
                return;
            }
            $context->builder->positionAtEnd($afterExitStatus);
        } elseif ([] === $enumEntries) {
            ScriptExit::emitStatusTypeErrorAndAbort($context, 'object');

            return;
        }

        ObjectEnumStringCastLlvm::emitEnumClassIdStringCastErrorChain(
            $context,
            $classId,
            $enumEntries,
            'exit_status_obj',
            $typeErrorBlock,
            true
        );
        $context->builder->positionAtEnd($typeErrorBlock);
        ScriptExit::emitStatusTypeErrorAndAbort($context, 'object');
    }

    /**
     * @return array<int, string>
     */
    private static function nonExitStatusEnumEntries(Object_ $object): array
    {
        $exitStatusId = $object->exitStatusEnumClassId();
        $enumEntries = $object->knownEnumClassIdsToNames();
        if (null !== $exitStatusId) {
            unset($enumEntries[$exitStatusId]);
        }

        return $enumEntries;
    }
}
