<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startAttributeNS() — open namespaced streaming attribute (php-src ext/xmlwriter/php_xmlwriter.c; #19446). */
final class XmlWriterStartAttributeNS extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startAttributeNS()');
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'XMLWriter::startAttributeNS() expects at least 4 arguments, '.$argc.' given'
            );
        }
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'XMLWriter::startAttributeNS()', 0, 'prefix');
        $name = $this->stringArg($frame->calledArgs[2], 'XMLWriter::startAttributeNS()', 1, 'name');
        $uri = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::startAttributeNS()', 2, 'uri');
        $ok = VmXmlWriter::startAttributeNS($entry, $prefix, $name, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
