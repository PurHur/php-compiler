<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::startAttribute() — begin streaming attribute (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19820). */
final class XmlWriterStartAttribute extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('startAttribute');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::startAttribute()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::startAttribute', 1);
        $name = $this->stringArg($frame->calledArgs[1], 'XMLWriter::startAttribute()', 0, 'name');
        $ok = VmXmlWriter::startAttribute($entry, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
