<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_cdata() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_write_cdata extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_cdata');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_cdata', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_write_cdata');
        $content = $this->stringArgAt($frame, 1, 'xmlwriter_write_cdata', 2, 'content');
        $ok = VmXmlWriter::writeCData($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
