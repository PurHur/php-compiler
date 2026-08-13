<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startPi() — open processing instruction (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19457). */
final class XmlWriterStartPI extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startPi');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startPi()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startPi', 1);
        $target = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startPi()', 0, 'target');
        $ok = VmXmlWriter::startPI($entry, $target);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
