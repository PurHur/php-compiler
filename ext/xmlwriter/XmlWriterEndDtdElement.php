<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endDtdElement() — close ELEMENT decl (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #20032). */
final class XmlWriterEndDtdElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endDtdElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endDtdElement()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endDtdElement', 0);
        $ok = VmXmlWriter::endDtdElement($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
