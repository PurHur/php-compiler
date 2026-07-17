<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startElementNS() — open namespaced element (php-src ext/xmlwriter/php_xmlwriter.c; #19446). */
final class XmlWriterStartElementNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startElementNS');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startElementNS()');
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'XMLWriter::startElementNS() expects at least 4 arguments, '.$argc.' given'
            );
        }
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::startElementNS()', 0, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::startElementNS()', 1, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::startElementNS()', 2, 'uri');
        $ok = VmXmlWriter::startElementNS($entry, $prefix, $name, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
