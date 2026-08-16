<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startElement() — open element (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterStartElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startElement()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startElement', 1);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startElement()', 0, $frame, 'name');
        $ok = VmXmlWriter::startElement($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
