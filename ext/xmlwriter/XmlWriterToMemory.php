<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLWriter::toMemory() — static in-memory factory (php-src zim_XMLWriter_toMemory; #19606, #30818).
 *
 * PHP 8.4+ only — gated by {@see \PHPCompiler\CompilerVersion::supportsXmlWriterFactories()}.
 */
final class XmlWriterToMemory extends XmlWriterClassMethod
{
    public function __construct()
    {
        parent::__construct('toMemory');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLWriter::toMemory() requires VM context');
        $this->requireExactUserArgCount($frame, 'XMLWriter::toMemory', 0, false);
        $writer = VmXmlWriter::toMemory($ctx);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($writer): void {
            $ret->object($writer);
        });
    }
}
