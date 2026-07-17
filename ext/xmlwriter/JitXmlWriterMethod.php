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
            'writeattribute' => JitXmlWriterUserScript::tryWriteAttribute($context, ...$args),
            'writeattributens' => JitXmlWriterUserScript::tryWriteAttributeNS($context, ...$args),
            'startattribute' => JitXmlWriterUserScript::tryStartAttribute($context, ...$args),
            'endattribute' => JitXmlWriterUserScript::tryEndAttribute($context, ...$args),
            'writeelementns' => JitXmlWriterUserScript::tryWriteElementNS($context, ...$args),
            'text' => JitXmlWriterUserScript::tryText($context, ...$args),
            'startcdata' => JitXmlWriterUserScript::tryStartCData($context, ...$args),
            'endcdata' => JitXmlWriterUserScript::tryEndCData($context, ...$args),
            'startcomment' => JitXmlWriterUserScript::tryStartComment($context, ...$args),
            'endcomment' => JitXmlWriterUserScript::tryEndComment($context, ...$args),
            'startdtd' => JitXmlWriterUserScript::tryStartDtd($context, ...$args),
            'enddtd' => JitXmlWriterUserScript::tryEndDtd($context, ...$args),
            'writedtd' => JitXmlWriterUserScript::tryWriteDtd($context, ...$args),
            'startpi' => JitXmlWriterUserScript::tryStartPI($context, ...$args),
            'endpi' => JitXmlWriterUserScript::tryEndPI($context, ...$args),
            'writepi' => JitXmlWriterUserScript::tryWritePI($context, ...$args),
            'writeraw' => JitXmlWriterUserScript::tryWriteRaw($context, ...$args),
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
