<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMProcessingInstruction::__construct(string $name, string $value = "")
 * — orphaned PI (php-src ext/dom/processinginstruction.c; #24631).
 */
final class ProcessingInstructionConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver(
            $frame,
            VmDom::CLASS_PROCESSING_INSTRUCTION,
            'DOMProcessingInstruction::__construct()'
        );
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'DOMProcessingInstruction::__construct() expects at least 1 argument, 0 given'
            );
        }
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMProcessingInstruction::__construct()',
            0,
            $frame,
            'name'
        );
        $value = '';
        if (isset($frame->calledArgs[2])) {
            $value = $this->stringArg(
                $frame->calledArgs[2],
                'DOMProcessingInstruction::__construct()',
                1,
                $frame,
                'value'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'DOMProcessingInstruction::__construct() requires VM context in this compiler build'
            );
        }
        VmDom::constructProcessingInstruction($frame->vmContext, $receiver, $name, $value);
    }
}
