<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeDtdEntity() — one-shot ENTITY (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19468). */
final class XmlWriterWriteDtdEntity extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeDtdEntity');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeDtdEntity()');
        $this->requireUserArgCountRange($frame, 'XMLWriter::writeDtdEntity', 2, 6);
        $argc = \count($frame->calledArgs);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeDtdEntity()', 0, 'name');
        $content = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeDtdEntity()', 1, 'content');
        $isParam = false;
        $publicId = null;
        $systemId = null;
        $notationData = null;
        if ($argc >= 4) {
            $isParam = $frame->calledArgs[3]->resolveIndirect()->toBool();
        }
        if ($argc >= 5) {
            $publicId = $this->nullableStringArg($frame->calledArgs[4], 'XMLWriter::writeDtdEntity()', 3, 'publicId');
        }
        if ($argc >= 6) {
            $systemId = $this->nullableStringArg($frame->calledArgs[5], 'XMLWriter::writeDtdEntity()', 4, 'systemId');
        }
        if ($argc >= 7) {
            $notationData = $this->nullableStringArg($frame->calledArgs[6], 'XMLWriter::writeDtdEntity()', 5, 'notationData');
        }
        $ok = VmXmlWriter::writeDtdEntity($entry, $name, $content, $isParam, $publicId, $systemId, $notationData);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
