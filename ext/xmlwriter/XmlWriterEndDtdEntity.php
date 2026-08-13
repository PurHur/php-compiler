<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::endDtdEntity() — close ENTITY decl (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #19468). */
final class XmlWriterEndDtdEntity extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('endDtdEntity');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::endDtdEntity()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::endDtdEntity', 0);
        $ok = VmXmlWriter::endDtdEntity($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
