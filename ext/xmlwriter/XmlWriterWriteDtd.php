<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeDtd() — one-shot DOCTYPE (php-src ext/xmlwriter/php_xmlwriter.c; #19386). */
final class XmlWriterWriteDtd extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeDtd');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeDtd()');
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'XMLWriter::writeDtd() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeDtd()', 0, 'name');
        $publicId = null;
        $systemId = null;
        $content = null;
        if ($argc >= 3) {
            $publicId = $this->nullableStringArg($frame->calledArgs[2], 'XMLWriter::writeDtd()', 1, 'publicId');
        }
        if ($argc >= 4) {
            $systemId = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::writeDtd()', 2, 'systemId');
        }
        if ($argc >= 5) {
            $content = $this->nullableStringArg($frame->calledArgs[4], 'XMLWriter::writeDtd()', 3, 'content');
        }
        $ok = VmXmlWriter::writeDtd($entry, $name, $publicId, $systemId, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
