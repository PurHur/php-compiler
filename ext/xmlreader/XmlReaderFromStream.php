<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::fromStream() — always-static factory (php-src zim_xmlreader_fromStream; #19607).
 *
 * PHP 8.4+ only — gated by {@see \PHPCompiler\CompilerVersion::supportsXmlReaderFactories()}.
 */
final class XmlReaderFromStream extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('fromStream');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLReader::fromStream() requires VM context');
        $this->requireUserArgCountRange($frame, 'XMLReader::fromStream', 1, 4, false);
        $streamVar = $frame->calledArgs[0]->resolveIndirect();
        if (!$streamVar->isStreamResource()) {
            throw new \TypeError(
                'XMLReader::fromStream(): Argument #1 ($stream) must be of type resource'
            );
        }
        if (!ResourceSupport::isOpenStreamResource($streamVar)) {
            throw new \ValueError('XMLReader::fromStream(): Argument #1 ($stream) is not an open stream resource');
        }
        $documentUri = null;
        if (isset($frame->calledArgs[3])) {
            $uriVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $uriVar->type) {
                if (Variable::TYPE_STRING !== $uriVar->type) {
                    throw new \TypeError(
                        'XMLReader::fromStream(): Argument #4 ($documentUri) must be of type ?string'
                    );
                }
                $documentUri = $uriVar->toString();
            }
        }
        $reader = VmXmlReader::fromStream($ctx, $streamVar, $documentUri, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($reader): void {
            $ret->object($reader);
        });
    }
}
