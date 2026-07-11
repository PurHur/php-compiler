<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/xmlwriter class methods (#6065). */
abstract class XmlWriterClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmXmlWriter::requireWriter(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            $label
        );
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName = 'value'): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                $var->toObject()->class->name
            ));
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, array given',
                $label,
                $index + 1,
                $paramName
            ));
        }

        return $var->toString();
    }

    protected function nullableStringArg(Variable $var, string $label, int $index, string $paramName = 'value'): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return $this->stringArg($var, $label, $index, $paramName);
    }
}
