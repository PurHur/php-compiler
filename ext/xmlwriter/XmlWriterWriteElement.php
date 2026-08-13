<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeElement() — element with optional content (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19340). */
final class XmlWriterWriteElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeElement()');
        $this->requireUserArgCountRange($frame, 'XMLWriter::writeElement', 1, 2);
        $argc = \count($frame->calledArgs);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeElement()', 0, 'name');
        $content = null;
        if ($argc >= 3) {
            $content = $this->nullableStringArg($frame->calledArgs[2], 'XMLWriter::writeElement()', 1, 'content');
        }
        $ok = VmXmlWriter::writeElement($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
