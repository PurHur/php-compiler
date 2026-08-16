<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeDtdElement() — ELEMENT decl (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19468). */
final class XmlWriterWriteDtdElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeDtdElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeDtdElement()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::writeDtdElement', 2);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeDtdElement()', 0, $frame, 'name');
        $content = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeDtdElement()', 1, $frame, 'content');
        $ok = VmXmlWriter::writeDtdElement($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
