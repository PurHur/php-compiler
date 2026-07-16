<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_output_memory() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_output_memory extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_output_memory');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_output_memory', 1, 2);
        $entry = $this->writerArg($frame, 'xmlwriter_output_memory');
        $flush = true;
        if (isset($frame->calledArgs[1])) {
            $flush = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $xml = VmXmlWriter::outputMemory($entry, $flush);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($xml): void {
            $ret->string($xml);
        });
    }
}
