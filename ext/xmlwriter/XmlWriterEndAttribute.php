<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endAttribute() — close streaming attribute (php-src ext/xmlwriter/php_xmlwriter.c; #19820). */
final class XmlWriterEndAttribute extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endAttribute');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endAttribute()');
        $argc = \count($frame->calledArgs) - 1;
        if (0 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'XMLWriter::endAttribute() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        $ok = VmXmlWriter::endAttribute($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
