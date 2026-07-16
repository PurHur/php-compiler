<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for XMLWriter instance methods — user-script AOT (#19551). */
final class JitXmlWriterMethod
{
    public static function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        $result = match ($methodLc) {
            'openmemory' => JitXmlWriterUserScript::tryOpenMemory($context, ...$args),
            'startdocument' => JitXmlWriterUserScript::tryStartDocument($context, ...$args),
            'startelement' => JitXmlWriterUserScript::tryStartElement($context, ...$args),
            'text' => JitXmlWriterUserScript::tryText($context, ...$args),
            'fullendelement' => JitXmlWriterUserScript::tryFullEndElement($context, ...$args),
            'endelement' => JitXmlWriterUserScript::tryEndElement($context, ...$args),
            'enddocument' => JitXmlWriterUserScript::tryEndDocument($context, ...$args),
            'outputmemory' => JitXmlWriterUserScript::tryOutputMemory($context, ...$args),
            'flush' => JitXmlWriterUserScript::tryFlush($context, ...$args),
            default => null,
        };
        if (null === $result) {
            throw new \LogicException(
                'XMLWriter::'.$methodLc.'() user-script AOT requires compile-time writer + literal args (#19551)'
            );
        }

        return $result;
    }
}
