<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_element_ns() (php-src php_xmlwriter.c; #20320). */
final class xmlwriter_write_element_ns extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_element_ns');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'xmlwriter_write_element_ns', 4, 5);
        $entry = $this->writerArg($frame, 'xmlwriter_write_element_ns');
        $prefix = $this->nullableStringArgAt($frame, 1, 'xmlwriter_write_element_ns', 2, 'prefix');
        $name = $this->stringArgAt($frame, 2, 'xmlwriter_write_element_ns', 3, 'name');
        $uri = $this->nullableStringArgAt($frame, 3, 'xmlwriter_write_element_ns', 4, 'namespace');
        $content = null;
        if (isset($frame->calledArgs[4])) {
            $content = $this->nullableStringArgAt($frame, 4,
                'xmlwriter_write_element_ns',
                5,
                'content'
            );
        }
        $ok = VmXmlWriter::writeElementNS($entry, $prefix, $name, $uri, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
