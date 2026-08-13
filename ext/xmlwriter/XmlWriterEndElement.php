<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endElement() — close current element (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterEndElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endElement()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endElement', 0);
        $ok = VmXmlWriter::endElement($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
