<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_element() (php-src php_xmlwriter.c; #19514). */
final class xmlwriter_write_element extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_element');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_write_element', 2, 3);
        $entry = $this->writerArg($frame, 'xmlwriter_write_element');
        $name = $this->stringArgAt($frame, 1, 'xmlwriter_write_element', 2, 'name');
        $content = null;
        if (isset($frame->calledArgs[2])) {
            $content = $this->nullableStringArgAt($frame, 2,
                'xmlwriter_write_element',
                3,
                'content'
            );
        }
        $ok = VmXmlWriter::writeElement($entry, $name, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
