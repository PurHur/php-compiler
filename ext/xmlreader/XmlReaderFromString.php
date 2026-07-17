<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::fromString() — always-static factory (php-src zim_xmlreader_fromString; #19607).
 *
 * PHP 8.4+ only — gated by {@see \PHPCompiler\CompilerVersion::supportsXmlReaderFactories()}.
 */
final class XmlReaderFromString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromString');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLReader::fromString() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::fromString() expects at least 1 argument, 0 given');
        }
        $sourceVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $sourceVar->type) {
            throw new \TypeError('XMLReader::fromString(): Argument #1 ($source) must be of type string');
        }
        $source = $sourceVar->toString();
        if ('' === $source) {
            throw new \ValueError('XMLReader::fromString(): Argument #1 ($source) cannot be empty');
        }
        // Optional $encoding / $flags accepted for signature parity; tokenizer ignores them for now.
        $reader = VmXmlReader::fromString($ctx, $source, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($reader): void {
            $ret->object($reader);
        });
    }
}
