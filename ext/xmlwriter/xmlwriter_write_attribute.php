<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_attribute() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_write_attribute extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_attribute');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_attribute', 3);
        $entry = $this->writerArg($frame, 'xmlwriter_write_attribute');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_write_attribute', 2, 'name');
        $value = $this->stringArgAt($frame, 2, 'xmlwriter_write_attribute', 3, 'value');
        $ok = VmXmlWriter::writeAttribute($entry, $name, $value);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
