<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::flush() — write URI buffer to disk (php-src ext/xmlwriter/php_xmlwriter.c; #6065). */
final class XmlWriterFlush extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('flush');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::flush()');
        $ok = VmXmlWriter::flush($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
