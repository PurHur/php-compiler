<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endPi() — close processing instruction (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19457). */
final class XmlWriterEndPI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endPi');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endPi()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endPi', 0);
        $ok = VmXmlWriter::endPI($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
