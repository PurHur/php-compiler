<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLWriter::text() — text node content (php-src ext/xmlwriter/php_xmlwriter.c; #30818; #6065). */
final class XmlWriterText extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('text');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XMLWriter::text()');
        $this->requireExactUserArgCount($frame, 'XMLWriter::text', 1);
        $var = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                'XMLWriter::text(): Argument #1 ($content) must be of type string, %s given',
                $var->toObject()->class->name
            ));
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError('XMLWriter::text(): Argument #1 ($content) must be of type string, array given');
        }
        $content = $var->toString();
        $ok = VmXmlWriter::text($entry, $content);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
