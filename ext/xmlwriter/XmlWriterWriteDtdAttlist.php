<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeDtdAttlist() — ATTLIST decl (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19468). */
final class XmlWriterWriteDtdAttlist extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeDtdAttlist');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeDtdAttlist()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::writeDtdAttlist', 2);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeDtdAttlist()', 0, 'name');
        $content = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeDtdAttlist()', 1, 'content');
        $ok = VmXmlWriter::writeDtdAttlist($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
