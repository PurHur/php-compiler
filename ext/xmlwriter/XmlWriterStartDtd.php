<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startDtd() — open DOCTYPE (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19386). */
final class XmlWriterStartDtd extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startDtd');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startDtd()');
        $this->requireUserArgCountRange($frame, 'XMLWriter::startDtd', 1, 3);
        $argc = \count($frame->calledArgs);
        $qualifiedName = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startDtd()', 0, $frame, 'qualifiedName');
        $publicId = null;
        $systemId = null;
        if ($argc >= 3) {
            $publicId = $this->nullableStringArg($frame->calledArgs[2], 'XMLWriter::startDtd()', 1, $frame, 'publicId');
        }
        if ($argc >= 4) {
            $systemId = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::startDtd()', 2, $frame, 'systemId');
        }
        $ok = VmXmlWriter::startDtd($entry, $qualifiedName, $publicId, $systemId);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
