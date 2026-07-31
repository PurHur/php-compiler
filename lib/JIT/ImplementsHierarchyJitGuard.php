<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\JIT\Builtin\ReadonlyRaise;
use PHPCompiler\VM\ImplementsHierarchyRuntimeCheck;

/**
 * JIT: runtime fatal for forbidden implements targets (#18781, Zend/zend_compile.c).
 */
final class ImplementsHierarchyJitGuard
{
    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public static function emitBeforeDeclare(
        Context $context,
        string $subjectDisplay,
        array $interfaceLcs,
        ?string $scriptPath,
        ?SourceLocation $sourceLocation,
        ?string $parentLc = null,
        bool $isEnum = false,
    ): void {
        $vmContext = $context->runtime->vmContext ?? null;
        foreach ($interfaceLcs as $targetLc) {
            $message = ImplementsHierarchyRuntimeCheck::forbiddenMessage(
                $subjectDisplay,
                $targetLc,
                $parentLc,
                $isEnum,
                $vmContext
            );
            if (null === $message) {
                continue;
            }

            $file = null !== $scriptPath && '' !== $scriptPath ? $scriptPath : 'Standard input code';
            if (null !== $sourceLocation && '' !== $sourceLocation->filename) {
                $file = $sourceLocation->filename;
            }
            $line = null !== $sourceLocation && $sourceLocation->startLine > 0
                ? $sourceLocation->startLine
                : 0;

            ReadonlyRaise::registerDeclarations($context);
            ReadonlyRaise::ensureLinked($context);
            ReadonlyRaise::emitRaise($context, sprintf(
                'Fatal error: %s in %s on line %d',
                $message,
                $file,
                $line
            ));

            return;
        }
    }
}
