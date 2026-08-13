<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::flush() — memory string / URI byte count (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065, #19385). */
final class XmlWriterFlush extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('flush');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::flush()');
        $this->requireAtMostUserArgCount($frame, 'XMLWriter::flush', 1);
        $empty = true;
        if (\count($frame->calledArgs) > 1) {
            $empty = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $result = VmXmlWriter::flush($entry, $empty);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (\is_string($result)) {
                $ret->string($result);
            } else {
                $ret->int((int) $result);
            }
        });
    }
}
