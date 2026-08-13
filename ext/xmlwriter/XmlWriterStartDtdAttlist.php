<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startDtdAttlist() — open ATTLIST decl (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #20025). */
final class XmlWriterStartDtdAttlist extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startDtdAttlist');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startDtdAttlist()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startDtdAttlist', 1);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startDtdAttlist()', 0, 'name');
        $ok = VmXmlWriter::startDtdAttlist($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
