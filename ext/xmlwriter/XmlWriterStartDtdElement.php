<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startDtdElement() — open ELEMENT decl (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #20032). */
final class XmlWriterStartDtdElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startDtdElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startDtdElement()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startDtdElement', 1);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startDtdElement()', 0, $frame, 'name');
        $ok = VmXmlWriter::startDtdElement($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
