<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_comment() (php-src php_xmlwriter.c; #20049). */
final class xmlwriter_start_comment extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_comment');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_comment', 1);
        $entry = $this->writerArg($frame, 'xmlwriter_start_comment');
        $ok = VmXmlWriter::startComment($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
