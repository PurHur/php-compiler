<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_start_attribute() (php-src php_xmlwriter.c; #19820). */
final class xmlwriter_start_attribute extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_start_attribute');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_start_attribute', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_start_attribute');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_start_attribute', 2, 'name');
        $ok = VmXmlWriter::startAttribute($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
