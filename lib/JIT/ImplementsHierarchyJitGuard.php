<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ReadonlyRaise;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
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

            self::emitCompileFatal($context, $message, $scriptPath, $sourceLocation);

            return;
        }
    }

    /**
     * Uncatchable JIT/AOT compile fatal (E_COMPILE_ERROR shape) at the current insert point.
     */
    public static function emitCompileFatal(
        Context $context,
        string $message,
        ?string $scriptPath,
        ?SourceLocation $sourceLocation,
    ): void {
        $file = null !== $scriptPath && '' !== $scriptPath ? $scriptPath : 'Standard input code';
        if (null !== $sourceLocation && '' !== $sourceLocation->filename) {
            $file = $sourceLocation->filename;
        }
        $line = null !== $sourceLocation && $sourceLocation->startLine > 0
            ? $sourceLocation->startLine
            : 0;

        $full = sprintf(
            'PHP Fatal error:  %s in %s on line %d',
            $message,
            $file,
            $line
        );

        // Standalone AOT: fprintf+exit — pending ReadonlyRaise abort helpers segfault on
        // this edge (#26538 / #25869; same pattern as ListUnpackHelper #25096).
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneFatalExit($context, $full);

            return;
        }

        ReadonlyRaise::registerDeclarations($context);
        ReadonlyRaise::ensureLinked($context);
        ReadonlyRaise::emitRaise($context, $full);
    }

    private static function emitStandaloneFatalExit(Context $context, string $fullMessage): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'exit',
            $context->context->functionType($context->getTypeFromString('void'), false, $i32)
        );
        $stderr = StringTriggerErrorJit::stderrFilePtr($context);
        $line = str_starts_with($fullMessage, 'PHP Fatal error:')
            ? $fullMessage."\n"
            : 'PHP Fatal error:  '.$fullMessage."\n";
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderr,
            $context->builder->pointerCast($context->constantFromString('%s'), $i8p),
            $context->builder->pointerCast($context->constantFromString($line), $i8p)
        );
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(255, false)
        );
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $deadBb = BasicBlockHelper::append($context, 'implements_hierarchy_fatal_dead');
        $context->builder->positionAtEnd($deadBb);
    }
}
