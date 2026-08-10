<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Call-site context for Zend-shaped user-function parameter TypeErrors (issues #156, #18853).
 */
final class UserParamErrorContext
{
    public function __construct(
        public readonly string $functionName,
        public readonly int $paramIndex,
        public readonly string $paramName,
        public readonly string $scriptPath,
        public readonly int $callSiteLine,
        public readonly bool $omitParamName = false,
    ) {
    }

    public static function forRecvFrame(Frame $frame, int $paramIndex, bool $omitParamName = false): ?self
    {
        if (null === $frame->block->func) {
            return null;
        }
        $paramName = $frame->block->paramNames[$paramIndex] ?? 'param'.$paramIndex;

        return new self(
            ParamArgumentCountError::formatUserFunctionName(
                ParamArgumentCountError::resolveFunctionName($frame)
            ),
            $paramIndex,
            $paramName,
            ...self::callSite($frame),
            omitParamName: $omitParamName,
        );
    }

    public function throwExpectedType(string $expected, Variable $argument): void
    {
        throw ParamTypeError::forUserCallWithExpectedType(
            $this->functionName,
            $this->paramIndex,
            $this->paramName,
            $expected,
            $argument,
            $this->scriptPath,
            $this->callSiteLine,
            $this->omitParamName
        );
    }

    public function throwConstraint(int $constraint, Variable $argument, ?string $literalBool = null): void
    {
        throw ParamTypeError::forUserCall(
            $this->functionName,
            $this->paramIndex,
            $this->paramName,
            $constraint,
            $argument,
            $this->scriptPath,
            $this->callSiteLine,
            $literalBool,
            $this->omitParamName
        );
    }

    /** @return array{0: string, 1: int} */
    private static function callSite(Frame $frame): array
    {
        $caller = $frame->parent ?? $frame;
        $scriptPath = '' !== $caller->scriptPath
            ? $caller->scriptPath
            : ExceptionSupport::throwSiteFile($caller);
        $callSiteLine = $caller->callSiteLine;
        if ($callSiteLine <= 0) {
            for ($f = $caller; null !== $f; $f = $f->parent) {
                if ($f->callSiteLine > 0) {
                    $callSiteLine = $f->callSiteLine;
                    break;
                }
            }
        }
        // Property-hook assign and other non-FUNCCALL invoke paths leave callSiteLine unset;
        // recover the user statement line from opcode source metadata (#29666).
        if ($callSiteLine <= 0) {
            $callSiteLine = FatalSite::lineFromOpcodes($caller);
        }
        if ($callSiteLine <= 0) {
            $callSiteLine = 1;
        }

        return [$scriptPath, $callSiteLine];
    }

}
