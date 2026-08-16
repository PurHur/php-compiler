<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_dtd_attlist() (php-src php_xmlwriter.c; #20025). */
final class xmlwriter_start_dtd_attlist extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_dtd_attlist');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_dtd_attlist', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_start_dtd_attlist');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_start_dtd_attlist', 2, 'name');
        $ok = VmXmlWriter::startDtdAttlist($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
