<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::openMemory() — in-memory buffer target (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterOpenMemory extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('openMemory');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::openMemory()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::openMemory', 0);
        $ok = VmXmlWriter::openMemory($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
