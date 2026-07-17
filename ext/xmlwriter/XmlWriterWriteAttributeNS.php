<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeAttributeNS() — namespaced attribute (php-src ext/xmlwriter/php_xmlwriter.c; #19371). */
final class XmlWriterWriteAttributeNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeAttributeNS()');
        $argc = \count($frame->calledArgs);
        if ($argc !== 5) {
            throw new \ArgumentCountError(
                'XMLWriter::writeAttributeNS() expects exactly 5 arguments, '.$argc.' given'
            );
        }
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::writeAttributeNS()', 0, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::writeAttributeNS()', 1, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::writeAttributeNS()', 2, 'uri');
        $content = $this->stringArg($frame->calledArgs[4], 'XMLWriter::writeAttributeNS()', 3, 'content');
        $ok = VmXmlWriter::writeAttributeNS($entry, $prefix, $name, $uri, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
