<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeElementNs() — namespaced element (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19371). */
final class XmlWriterWriteElementNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeElementNs');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeElementNs()');
        $this->requireUserArgCountRange($frame, 'XMLWriter::writeElementNs', 3, 4);
        $argc = \count($frame->calledArgs);
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::writeElementNs()', 0, $frame, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeElementNs()', 1, $frame, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::writeElementNs()', 2, $frame, 'uri');
        $content = null;
        if ($argc >= 5) {
            $content = $this->nullableStringArg($frame->calledArgs[4], 'XMLWriter::writeElementNs()', 3, $frame, 'content');
        }
        $ok = VmXmlWriter::writeElementNS($entry, $prefix, $name, $uri, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
