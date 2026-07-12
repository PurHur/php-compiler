<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startElement() — open element (php-src ext/xmlwriter/php_xmlwriter.c; #6065). */
final class XmlWriterStartElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startElement()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('XMLWriter::startElement() expects at least 2 arguments, 1 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startElement()', 0, 'name');
        $ok = VmXmlWriter::startElement($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
