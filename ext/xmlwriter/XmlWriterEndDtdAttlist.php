<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endDtdAttlist() — close ATTLIST decl (php-src ext/xmlwriter/php_xmlwriter.c; #20025). */
final class XmlWriterEndDtdAttlist extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endDtdAttlist');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endDtdAttlist()');
        $argc = \count($frame->calledArgs);
        if ($argc !== 1) {
            throw new \ArgumentCountError(
                'XMLWriter::endDtdAttlist() expects exactly 0 arguments, '.($argc - 1).' given'
            );
        }
        $ok = VmXmlWriter::endDtdAttlist($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
