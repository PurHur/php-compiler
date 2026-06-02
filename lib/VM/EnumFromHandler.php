<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * VM handler for BackedEnum::from() and ::tryFrom() (#3114).
 */
final class EnumFromHandler extends Internal
{
    public function __construct(
        private ClassEntry $enum,
        private bool $try,
    ) {
        parent::__construct($enum->name.'::'.($try ? 'tryFrom' : 'from'));
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException(
                $this->getName().'() requires exactly 1 argument in this compiler build'
            );
        }
        $arg = $frame->calledArgs[0];
        $match = BackedEnum::caseForValue($this->enum, $arg);
        if (null === $match) {
            if (!$this->try) {
                throw new \ValueError(BackedEnum::valueErrorMessage($this->enum, $arg));
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->enumCase($match);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not supported in JIT in this compiler build');
    }
}
