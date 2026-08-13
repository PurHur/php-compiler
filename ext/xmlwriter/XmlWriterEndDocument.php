<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endDocument() — finalize document (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterEndDocument extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endDocument');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endDocument()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endDocument', 0);
        $ok = VmXmlWriter::endDocument($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
