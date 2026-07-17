<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endCData() — close CDATA section (php-src ext/xmlwriter/php_xmlwriter.c; #19457). */
final class XmlWriterEndCData extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endCData');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endCData()');
        $ok = VmXmlWriter::endCData($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
