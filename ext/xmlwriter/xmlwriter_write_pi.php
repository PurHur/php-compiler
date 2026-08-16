<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_pi() (php-src php_xmlwriter.c; #20049). */
final class xmlwriter_write_pi extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_pi');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_pi', 3);
        $entry = $this->writerArg($frame, 'xmlwriter_write_pi');
        $target = $this->stringArgAt($frame, 1, 'xmlwriter_write_pi', 2, 'target');
        $content = $this->stringArgAt($frame, 2, 'xmlwriter_write_pi', 3, 'content');
        $ok = VmXmlWriter::writePI($entry, $target, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
