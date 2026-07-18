<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_cdata() (php-src php_xmlwriter.c; #20322). */
final class xmlwriter_start_cdata extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_cdata');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_cdata', 1);
        $entry = $this->writerArg($frame, 'xmlwriter_start_cdata');
        $ok = VmXmlWriter::startCData($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
