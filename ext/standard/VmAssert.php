<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;

/**
 * assert() VM semantics (ext/standard/assert.c; issues #3157, #3316).
 */
final class VmAssert
{
    public static function evaluate(
        Frame $frame,
        Variable $assertion,
        ?Variable $description = null
    ): bool {
        if (!VmAssertState::isEnabled()) {
            return true;
        }
        self::validateDescription($description);
        if (boolval::isTruthy($assertion)) {
            return true;
        }
        self::fail($frame, $description);

        return false;
    }

    private static function validateDescription(?Variable $description): void
    {
        if (null === $description) {
            return;
        }
        $desc = $description->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($desc)) {
            throw new \TypeError(sprintf(
                'assert(): Argument #2 ($description) must be of type string|Throwable, %s given',
                EnumCaseSupport::typeNameForVariable($desc)
            ));
        }
        if (Variable::TYPE_OBJECT === $desc->type) {
            $object = $desc->toObject();
            if (!ExceptionSupport::objectImplementsThrowable($object)) {
                throw new \TypeError(sprintf(
                    'assert(): Argument #2 ($description) must be of type Throwable|string|null, %s given',
                    $object->class->name
                ));
            }
        }
    }

    private static function fail(Frame $frame, ?Variable $description): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('assert() requires VM context');
        }
        [$exceptionMessage, $warningMessage] = self::buildMessages($description);
        if (VmAssertState::shouldThrowOnFailure()) {
            throw new \AssertionError($exceptionMessage);
        }
        $frame->vmContext->errors->triggerError(
            $warningMessage,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
        if (VmAssertState::shouldBailOnFailure()) {
            exit(1);
        }
    }

    /**
     * @return array{0: string, 1: string} exception message, warning message
     */
    private static function buildMessages(?Variable $description): array
    {
        $default = 'assert(): assert(false) failed';
        if (null === $description) {
            return [$default, $default];
        }
        $desc = $description->resolveIndirect();
        if (Variable::TYPE_STRING === $desc->type) {
            $text = $desc->toString();

            return [$text, self::warningMessageForDescription($text)];
        }
        if (Variable::TYPE_OBJECT === $desc->type) {
            $object = $desc->toObject();
            $message = $object->getProperty(ExceptionSupport::PROP_MESSAGE)->resolveIndirect()->toString();

            return [$message, self::warningMessageForDescription($message)];
        }

        return [$default, $default];
    }

    private static function warningMessageForDescription(string $description): string
    {
        return 'assert(): '.$description.' failed';
    }
}
