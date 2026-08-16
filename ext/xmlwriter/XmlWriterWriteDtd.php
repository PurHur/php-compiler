<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeDtd() — one-shot DOCTYPE (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19386). */
final class XmlWriterWriteDtd extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeDtd');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeDtd()');
        $this->requireUserArgCountRange($frame, 'XMLWriter::writeDtd', 1, 4);
        $argc = \count($frame->calledArgs);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeDtd()', 0, $frame, 'name');
        $publicId = null;
        $systemId = null;
        $content = null;
        if ($argc >= 3) {
            $publicId = $this->nullableStringArg($frame->calledArgs[2], 'XMLWriter::writeDtd()', 1, $frame, 'publicId');
        }
        if ($argc >= 4) {
            $systemId = $this->nullableStringArg($frame->calledArgs[3], 'XMLWriter::writeDtd()', 2, $frame, 'systemId');
        }
        if ($argc >= 5) {
            $content = $this->nullableStringArg($frame->calledArgs[4], 'XMLWriter::writeDtd()', 3, $frame, 'content');
        }
        $ok = VmXmlWriter::writeDtd($entry, $name, $publicId, $systemId, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
