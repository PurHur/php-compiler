<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_dtd_element() (php-src php_xmlwriter.c; #20322). */
final class xmlwriter_write_dtd_element extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_dtd_element');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_dtd_element', 3);
        $entry = $this->writerArg($frame, 'xmlwriter_write_dtd_element');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_write_dtd_element', 2, 'name');
        $content = $this->stringArgAt($frame, 2, 'xmlwriter_write_dtd_element', 3, 'content');
        $ok = VmXmlWriter::writeDtdElement($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
