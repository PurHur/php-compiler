<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for procedural xmlwriter_* (php-src ext/xmlwriter/php_xmlwriter.c; #19514).
 */
abstract class XmlWriterProceduralFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!JitXmlWriterUserScript::isUserScriptAot()) {
            throw new \LogicException(
                $this->getName().'() is not JIT-lowered outside user-script AOT (#19514)'
            );
        }
        $result = JitXmlWriterUserScript::tryProcedural($context, $this->getName(), ...$args);
        if (null === $result) {
            throw new \LogicException(
                $this->getName().'() user-script AOT requires compile-time writer + literal args (#19514)'
            );
        }

        return $result;
    }

    protected function writerArg(Frame $frame, string $function): ObjectEntry
    {
        $this->requireAtLeastArgCount($frame, $function, 1);
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type
            || VmXmlWriter::CLASS_LC !== strtolower($var->toObject()->class->name)
        ) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($writer) must be of type XMLWriter, %s given',
                $function,
                VmXmlWriter::typeLabel($var)
            ));
        }

        return $var->toObject();
    }

    /**
     * Procedural string arg — 1-based index includes $writer as argument #1.
     * Z_PARAM_STR soft-null DEP (#31610).
     */
    protected function stringArgAt(Frame $frame, int $frameArgIndex, string $function, int $argNum, string $paramName): string
    {
        $var = $frame->calledArgs[$frameArgIndex]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $function,
                $argNum,
                $paramName,
                $var->toObject()->class->name
            ));
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, array given',
                $function,
                $argNum,
                $paramName
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            VmNullStringParamDeprecation::emit($frame, $function, $argNum - 1, $paramName);

            return '';
        }

        return $var->toString();
    }

    protected function nullableStringArgAt(Frame $frame, int $frameArgIndex, string $function, int $argNum, string $paramName): ?string
    {
        $var = $frame->calledArgs[$frameArgIndex]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return $this->stringArgAt($frame, $frameArgIndex, $function, $argNum, $paramName);
    }

    protected function newWriter(Frame $frame): ObjectEntry
    {
        $class = $frame->vmContext->classes[VmXmlWriter::CLASS_LC]
            ?? throw new \LogicException('XMLWriter class not registered');

        return new ObjectEntry($class);
    }
}
