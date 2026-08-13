<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::fromUri() — always-static factory (php-src zim_xmlreader_fromUri; #19607).
 *
 * PHP 8.4+ only — gated by {@see \PHPCompiler\CompilerVersion::supportsXmlReaderFactories()}.
 */
final class XmlReaderFromUri extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('fromUri');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLReader::fromUri() requires VM context');
        $this->requireUserArgCountRange($frame, 'XMLReader::fromUri', 1, 3, false);
        $uriVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $uriVar->type) {
            throw new \TypeError('XMLReader::fromUri(): Argument #1 ($uri) must be of type string');
        }
        $uri = $uriVar->toString();
        if ('' === $uri) {
            throw new \ValueError('XMLReader::fromUri(): Argument #1 ($uri) cannot be empty');
        }
        $reader = VmXmlReader::fromUri($ctx, $uri, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($reader): void {
            if (null === $reader) {
                // php-src throws Error / Warning on open failure for fromUri — surface as Error for now.
                throw new \Error('XMLReader::fromUri(): Unable to open source data');
            }
            $ret->object($reader);
        });
    }
}
