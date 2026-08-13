<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startCdata() — open CDATA section (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19457). */
final class XmlWriterStartCData extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startCdata');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startCdata()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startCdata', 0);
        $ok = VmXmlWriter::startCData($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
