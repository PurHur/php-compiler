<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** xmlwriter_write_attribute_ns() (php-src php_xmlwriter.c; #20320). */
final class xmlwriter_write_attribute_ns extends XmlWriterProceduralFunction
{
    public function __construct()
    {
        parent::__construct('xmlwriter_write_attribute_ns');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlwriter_write_attribute_ns', 5);
        $entry = $this->writerArg($frame, 'xmlwriter_write_attribute_ns');
        $prefix = $this->nullableStringArgAt($frame, 1, 'xmlwriter_write_attribute_ns', 2, 'prefix');
        $name = $this->stringArgAt($frame, 2, 'xmlwriter_write_attribute_ns', 3, 'name');
        $uri = $this->nullableStringArgAt($frame, 3, 'xmlwriter_write_attribute_ns', 4, 'namespace');
        $value = $this->stringArgAt($frame, 4, 'xmlwriter_write_attribute_ns', 5, 'value');
        $ok = VmXmlWriter::writeAttributeNS($entry, $prefix, $name, $uri, $value);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
