<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_open_memory() — allocate in-memory writer (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_open_memory extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_open_memory');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_open_memory', 0);
        $entry = $this->newWriter($frame);
        VmXmlWriter::openMemory($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($entry): void {
            $ret->object($entry);
        });
    }
}
