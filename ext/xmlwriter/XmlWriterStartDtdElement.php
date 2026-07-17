<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startDtdElement() — open ELEMENT decl (php-src ext/xmlwriter/php_xmlwriter.c; #20032). */
final class XmlWriterStartDtdElement extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startDtdElement');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startDtdElement()');
        $argc = \count($frame->calledArgs);
        if ($argc !== 2) {
            throw new \ArgumentCountError(
                'XMLWriter::startDtdElement() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startDtdElement()', 0, 'name');
        $ok = VmXmlWriter::startDtdElement($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
