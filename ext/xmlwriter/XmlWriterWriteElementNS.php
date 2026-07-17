<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeElementNS() — namespaced element (php-src ext/xmlwriter/php_xmlwriter.c; #19371). */
final class XmlWriterWriteElementNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeElementNS');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeElementNS()');
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'XMLWriter::writeElementNS() expects at least 4 arguments, '.$argc.' given'
            );
        }
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::writeElementNS()', 0, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeElementNS()', 1, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::writeElementNS()', 2, 'uri');
        $content = null;
        if ($argc >= 5) {
            $content = $this->nullableStringArg($frame->calledArgs[4], 'XMLWriter::writeElementNS()', 3, 'content');
        }
        $ok = VmXmlWriter::writeElementNS($entry, $prefix, $name, $uri, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
