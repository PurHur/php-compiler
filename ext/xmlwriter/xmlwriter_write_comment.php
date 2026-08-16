<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_comment() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_write_comment extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_comment');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_comment', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_write_comment');
        $content = $this->stringArgAt($frame, 1, 'xmlwriter_write_comment', 2, 'content');
        $ok = VmXmlWriter::writeComment($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
