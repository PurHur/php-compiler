<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startAttributeNs() — open namespaced streaming attribute (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19446). */
final class XmlWriterStartAttributeNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startAttributeNs');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startAttributeNs()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startAttributeNs', 3);
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::startAttributeNs()', 0, $frame, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::startAttributeNs()', 1, $frame, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::startAttributeNs()', 2, $frame, 'uri');
        $ok = VmXmlWriter::startAttributeNS($entry, $prefix, $name, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
