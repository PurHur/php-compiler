<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_set_indent_string() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_set_indent_string extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_set_indent_string');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_set_indent_string', 2);
        $entry = $this->writerArg($frame, 'xmlwriter_set_indent_string');
        $indentation = $this->stringArgAt($frame, 1,
            'xmlwriter_set_indent_string',
            2,
            'indentation'
        );
        $ok = VmXmlWriter::setIndentString($entry, $indentation);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
