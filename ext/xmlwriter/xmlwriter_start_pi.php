<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_pi() (php-src php_xmlwriter.c; #20049). */
final class xmlwriter_start_pi extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_pi');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_pi', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_start_pi');
        $target = $this->stringArgAt($frame, 1, 'xmlwriter_start_pi', 2, 'target');
        $ok = VmXmlWriter::startPI($entry, $target);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
