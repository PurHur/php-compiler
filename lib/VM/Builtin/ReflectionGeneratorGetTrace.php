<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionGenerator::getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT): array — VM (#22067). */
final class ReflectionGeneratorGetTrace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTrace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        $options = VmDebugBacktrace::PROVIDE_OBJECT;
        if (\count($frame->calledArgs) >= 2) {
            $optionsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError(sprintf(
                    'ReflectionGenerator::getTrace(): Argument #1 ($options) must be of type int, %s given',
                    EnumCaseSupport::typeNameForVariable($optionsVar)
                ));
            }
            $options = $optionsVar->toInt();
        }
        $trace = GeneratorTrace::buildTrace($gen, $options);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($trace);
        }
    }
}
