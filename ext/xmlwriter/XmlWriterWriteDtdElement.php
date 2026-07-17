<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeDtdElement() — ELEMENT decl (php-src ext/xmlwriter/php_xmlwriter.c; #19468). */
final class XmlWriterWriteDtdElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeDtdElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeDtdElement()');
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'XMLWriter::writeDtdElement() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeDtdElement()', 0, 'name');
        $content = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeDtdElement()', 1, 'content');
        $ok = VmXmlWriter::writeDtdElement($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
