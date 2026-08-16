<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_text() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_text extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_text');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_text', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_text');
        $content = $this->stringArgAt($frame, 1, 'xmlwriter_text', 2, 'content');
        $ok = VmXmlWriter::text($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
