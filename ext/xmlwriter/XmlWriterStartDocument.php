<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startDocument() — XML declaration (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterStartDocument extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startDocument');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startDocument()');
        $this->requireAtMostUserArgCount($frame, 'XMLWriter::startDocument', 3);
        $version = '1.0';
        if (isset($frame->calledArgs[1])) {
            $version = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::startDocument()', 0, $frame, 'version');
        }
        $encoding = null;
        if (isset($frame->calledArgs[2])) {
            $encoding = $this->nullableStringArg($frame->calledArgs[2], 'XMLWriter::startDocument()', 1, $frame, 'encoding');
        }
        $ok = VmXmlWriter::startDocument($entry, $version, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
