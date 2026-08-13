<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endCdata() — close CDATA section (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19457). */
final class XmlWriterEndCData extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endCdata');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endCdata()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endCdata', 0);
        $ok = VmXmlWriter::endCData($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
