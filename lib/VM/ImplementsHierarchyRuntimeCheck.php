<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;

/**
 * Runtime implements guards deferred from compile time (#18781, Zend/zend_compile.c).
 *
 * php-src: zend_check_implement_interface — DateTimeInterface and internal classes
 * fatal when the class declaration executes, not at parseAndCompile().
 */
final class ImplementsHierarchyRuntimeCheck
{
    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public static function assertAllowed(
        string $subjectDisplay,
        array $interfaceLcs,
        Context $context,
        Frame $frame,
        ?SourceLocation $sourceLocation = null,
    ): void {
        foreach ($interfaceLcs as $targetLc) {
            $message = self::forbiddenMessage($subjectDisplay, $targetLc);
            if (null !== $message) {
                self::throwFatal($message, $frame, $sourceLocation);
            }
        }
    }

    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public static function requiresSourceOrderRegistration(string $subjectDisplay, array $interfaceLcs): bool
    {
        foreach ($interfaceLcs as $targetLc) {
            if (null !== self::forbiddenMessage($subjectDisplay, $targetLc)) {
                return true;
            }
        }

        return false;
    }

    public static function forbiddenMessage(string $subjectDisplay, string $targetLc): ?string
    {
        if (DateTimeInterfaceSupport::rejectsUserImplementationLc($targetLc)) {
            return DateTimeInterfaceSupport::USER_IMPLEMENTATION_FORBIDDEN_MESSAGE;
        }

        return ReservedBuiltinClass::compileTimeImplementsForbiddenMessage($subjectDisplay, $targetLc);
    }

    /**
     * @return never
     */
    private static function throwFatal(string $message, Frame $frame, ?SourceLocation $sourceLocation): void
    {
        $file = '' !== $frame->scriptPath ? $frame->scriptPath : 'Standard input code';
        if (null !== $sourceLocation && '' !== $sourceLocation->filename) {
            $file = $sourceLocation->filename;
        }
        $line = null !== $sourceLocation && $sourceLocation->startLine > 0
            ? $sourceLocation->startLine
            : 0;

        throw new \LogicException(sprintf(
            'Fatal error: %s in %s on line %d',
            $message,
            $file,
            $line
        ));
    }
}
