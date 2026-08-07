<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * hebrev() — convert logical Hebrew text to visual text (php-src string.c parity, #3450).
 *
 * VM: {@see VmHebrev::convert()}; JIT/AOT via {@see JitHebrev} + {@see HebrevJitHelper} (#3450).
 */
final class hebrev extends Internal
{
    public function __construct()
    {
        parent::__construct('hebrev');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28313).
        $this->requireArgCountRange($frame, 'hebrev', 1, 2);
        $argc = \count($frame->calledArgs);
        $string = self::vmStringArg($frame, 0, 'string');
        $maxCharsPerLine = 0;
        if ($argc >= 2) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('hebrev() max_chars_per_line must be an integer in this compiler build');
            }
            $maxCharsPerLine = $maxVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmHebrev::convert($string, $maxCharsPerLine));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitHebrev::invoke($context, ...$args);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'hebrev', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21421, ext/standard/string.c).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'hebrev',
            $argIndex,
            $paramName
        );
    }
}
