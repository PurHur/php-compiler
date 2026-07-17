<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startDtdEntity() — open ENTITY decl (php-src ext/xmlwriter/php_xmlwriter.c; #19468). */
final class XmlWriterStartDtdEntity extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startDtdEntity');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startDtdEntity()');
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'XMLWriter::startDtdEntity() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startDtdEntity()', 0, 'name');
        $isParam = $frame->calledArgs[2]->resolveIndirect()->toBool();
        $ok = VmXmlWriter::startDtdEntity($entry, $name, $isParam);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
