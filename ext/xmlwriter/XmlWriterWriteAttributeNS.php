<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeAttributeNs() — namespaced attribute (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19371). */
final class XmlWriterWriteAttributeNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeAttributeNs');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeAttributeNs()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::writeAttributeNs', 4);
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::writeAttributeNs()', 0, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeAttributeNs()', 1, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::writeAttributeNs()', 2, 'uri');
        $content = $this->stringArg($frame->calledArgs[4], 'XMLWriter::writeAttributeNs()', 3, 'content');
        $ok = VmXmlWriter::writeAttributeNS($entry, $prefix, $name, $uri, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
