<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_dtd_attlist() (php-src php_xmlwriter.c; #20322). */
final class xmlwriter_write_dtd_attlist extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_dtd_attlist');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_dtd_attlist', 3);
        $entry = $this->writerArg($frame, 'xmlwriter_write_dtd_attlist');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_write_dtd_attlist', 2, 'name');
        $content = $this->stringArgAt($frame, 2, 'xmlwriter_write_dtd_attlist', 3, 'content');
        $ok = VmXmlWriter::writeDtdAttlist($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
