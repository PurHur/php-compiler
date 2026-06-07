<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\ExceptionTraceFormat;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Throwable::__toString() — VM (#7159). */
final class ExceptionToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('__toString() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], '__toString()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(self::formatChain($receiver));
    }

    private static function formatChain(ObjectEntry $outer): string
    {
        $chain = [];
        for ($current = $outer; ; ) {
            $chain[] = $current;
            $prev = $current->getProperty(ExceptionSupport::PROP_PREVIOUS)->resolveIndirect();
            if (Variable::TYPE_NULL === $prev->type) {
                break;
            }
            if (Variable::TYPE_OBJECT !== $prev->type) {
                break;
            }
            $current = $prev->toObject();
        }
        $chain = array_reverse($chain);

        $blocks = [];
        foreach ($chain as $index => $entry) {
            $blocks[] = self::formatOne($entry, 0 === $index);
        }

        return implode("\n\n", $blocks);
    }

    private static function formatOne(ObjectEntry $entry, bool $includeClass): string
    {
        $class = $entry->class->name;
        $message = ExceptionSupport::readThrowableMessage($entry);
        $file = $entry->getProperty(ExceptionSupport::PROP_FILE)->optionalScalarString() ?? '';
        $lineVar = $entry->getProperty(ExceptionSupport::PROP_LINE)->resolveIndirect();
        $line = Variable::TYPE_INTEGER === $lineVar->type ? $lineVar->toInt() : 0;

        $location = '';
        if ('' !== $file) {
            $location = " in {$file}";
            if ($line > 0) {
                $location .= ":{$line}";
            }
        }
        if ($includeClass) {
            $header = "{$class}: {$message}{$location}";
        } else {
            $header = "Next Exception: {$message}{$location}";
        }
        $trace = ExceptionTraceFormat::asString($entry->getProperty(ExceptionSupport::PROP_TRACE));

        return "{$header}\nStack trace:\n{$trace}";
    }
}
