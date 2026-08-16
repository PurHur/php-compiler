<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_raw() (php-src php_xmlwriter.c; #20049). */
final class xmlwriter_write_raw extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_raw');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_raw', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_write_raw');
        $content = $this->stringArgAt($frame, 1, 'xmlwriter_write_raw', 2, 'content');
        $ok = VmXmlWriter::writeRaw($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
