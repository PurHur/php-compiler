<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_element() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_start_element extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_element');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_element', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_start_element');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_start_element', 2, 'name');
        $ok = VmXmlWriter::startElement($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
