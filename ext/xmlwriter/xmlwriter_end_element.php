<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_end_element() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_end_element extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_end_element');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_end_element', 1);
        $entry = $this->writerArg($frame, 'xmlwriter_end_element');
        $ok = VmXmlWriter::endElement($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
