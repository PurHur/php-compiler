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
 *
 * php-src: Zend/zend_execute_API.c — missing implements target → Error Interface "%s" not found
 * after spl_autoload (#25624). Same-file forward refs rely on Compiler hoisting interfaces
 * before classes so DECLARE_INTERFACE runs first.
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
        ?string $parentLc = null,
        bool $isEnum = false,
    ): void {
        foreach ($interfaceLcs as $targetLc) {
            $message = self::forbiddenMessage(
                $subjectDisplay,
                $targetLc,
                $parentLc,
                $isEnum,
                $context
            );
            if (null !== $message) {
                self::throwFatal($message, $frame, $sourceLocation);
            }
        }
    }

    /**
     * Autoload then require each implements target to exist as an interface (#25624).
     *
     * @param list<string> $interfaceLcs lowercase interface names
     * @param list<string> $interfaceDisplays source-cased names (parallel to $interfaceLcs)
     *
     * @return string|null Error message when unresolved; null when all ok
     */
    public static function missingInterfaceMessage(
        array $interfaceLcs,
        array $interfaceDisplays,
        Context $context,
    ): ?string {
        foreach ($interfaceLcs as $i => $ifaceLc) {
            $display = $interfaceDisplays[$i] ?? $ifaceLc;
            if (!isset($context->classes[$ifaceLc])) {
                $context->autoloadClass($display);
            }
            if (!isset($context->classes[$ifaceLc])) {
                return sprintf('Interface "%s" not found', $display);
            }
        }

        return null;
    }

    /**
     * @param list<string> $interfaceLcs
     * @param list<string> $interfaceDisplays
     *
     * @return string|null Zend "C cannot implement Y - it is not an interface" or null
     */
    public static function notInterfaceMessage(
        string $subjectDisplay,
        array $interfaceLcs,
        array $interfaceDisplays,
        Context $context,
    ): ?string {
        foreach ($interfaceLcs as $i => $ifaceLc) {
            if (!isset($context->classes[$ifaceLc])) {
                continue;
            }
            $entry = $context->classes[$ifaceLc];
            if ($entry->isInterface) {
                continue;
            }
            $display = $interfaceDisplays[$i] ?? $ifaceLc;

            return sprintf(
                '%s cannot implement %s - it is not an interface',
                $subjectDisplay,
                '' !== $entry->name ? $entry->name : $display
            );
        }

        return null;
    }

    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public static function requiresSourceOrderRegistration(
        string $subjectDisplay,
        array $interfaceLcs,
        ?string $parentLc = null,
        bool $isEnum = false,
        ?Context $context = null,
    ): bool {
        foreach ($interfaceLcs as $targetLc) {
            if (null !== self::forbiddenMessage($subjectDisplay, $targetLc, $parentLc, $isEnum, $context)) {
                return true;
            }
        }

        return false;
    }

    public static function forbiddenMessage(
        string $subjectDisplay,
        string $targetLc,
        ?string $parentLc = null,
        bool $isEnum = false,
        ?Context $context = null,
    ): ?string {
        if (DateTimeInterfaceSupport::rejectsUserImplementationLc($targetLc)) {
            return DateTimeInterfaceSupport::USER_IMPLEMENTATION_FORBIDDEN_MESSAGE;
        }
        if (ExceptionSupport::isThrowableInterfaceLc($targetLc)) {
            return ExceptionSupport::userImplementsThrowableForbiddenMessage(
                $subjectDisplay,
                $isEnum,
                $parentLc,
                $context
            );
        }
        // php-src zend_enum.c — enum + Serializable is a hard ban (not the class E_DEPRECATED path).
        if ($isEnum) {
            $serializableBan = EnumSupport::serializableImplementationForbiddenMessage(
                $subjectDisplay,
                $targetLc
            );
            if (null !== $serializableBan) {
                return $serializableBan;
            }
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
            'PHP Fatal error:  %s in %s on line %d',
            $message,
            $file,
            $line
        ));
    }
}
