<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endDtd() — close DOCTYPE (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19386). */
final class XmlWriterEndDtd extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endDtd');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endDtd()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endDtd', 0);
        $ok = VmXmlWriter::endDtd($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
