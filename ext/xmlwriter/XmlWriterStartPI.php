<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startPI() — open processing instruction (php-src ext/xmlwriter/php_xmlwriter.c; #19457). */
final class XmlWriterStartPI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startPI');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startPI()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'XMLWriter::startPI() expects at least 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $target = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startPI()', 0, 'target');
        $ok = VmXmlWriter::startPI($entry, $target);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
