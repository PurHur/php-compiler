<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::writeRaw() — unescaped markup (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19371). */
final class XmlWriterWriteRaw extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('writeRaw');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::writeRaw()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::writeRaw', 1);
        $content = $this->stringArg($frame->calledArgs[1], 'XMLWriter::writeRaw()', 0, 'content');
        $ok = VmXmlWriter::writeRaw($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
