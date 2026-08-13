<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endAttribute() — close streaming attribute (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19820). */
final class XmlWriterEndAttribute extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endAttribute');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endAttribute()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endAttribute', 0);
        $ok = VmXmlWriter::endAttribute($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
