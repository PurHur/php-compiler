<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLWriter::fullEndElement() — always emit explicit close tags (php-src zim_XMLWriter_fullEndElement; #19551).
 */
final class XmlWriterFullEndElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('fullEndElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::fullEndElement()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::fullEndElement', 0);
        $ok = VmXmlWriter::fullEndElement($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
