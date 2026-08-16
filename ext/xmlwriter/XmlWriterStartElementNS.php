<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startElementNs() — open namespaced element (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19446). */
final class XmlWriterStartElementNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startElementNs');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startElementNs()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startElementNs', 3);
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::startElementNs()', 0, $frame, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::startElementNs()', 1, $frame, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::startElementNs()', 2, $frame, 'uri');
        $ok = VmXmlWriter::startElementNS($entry, $prefix, $name, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
