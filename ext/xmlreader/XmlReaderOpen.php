<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::open() — file open (php-src ext/xmlreader/php_xmlreader.c; #6135, #19330).
 *
 * Static call returns XMLReader|false; instance call mutates $this and returns bool.
 */
final class XmlReaderOpen extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('open');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLReader::open() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::open() expects at least 1 argument, 0 given');
        }

        $first = $frame->calledArgs[0]->resolveIndirect();
        $instanceCall = Variable::TYPE_OBJECT === $first->type
            && VmXmlReader::CLASS_LC === strtolower($first->toObject()->class->name);

        if ($instanceCall) {
            if (\count($frame->calledArgs) < 2) {
                throw new \ArgumentCountError('XMLReader::open() expects at least 1 argument, 0 given');
            }
            $uriVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $uriVar->type) {
                throw new \TypeError('XMLReader::open(): Argument #1 ($uri) must be of type string');
            }
            $uri = $uriVar->toString();
            // Before VmFsReadPure/fopen — host fopen('') throws generic "Path must not be empty" (#24810).
            if ('' === $uri) {
                throw new \ValueError('XMLReader::open(): Argument #1 ($uri) cannot be empty');
            }
            $ok = VmXmlReader::openOnto($ctx, $first->toObject(), $uri, $frame);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            });

            return;
        }

        if (Variable::TYPE_STRING !== $first->type) {
            throw new \TypeError('XMLReader::open(): Argument #1 ($uri) must be of type string');
        }
        $uri = $first->toString();
        if ('' === $uri) {
            throw new \ValueError('XMLReader::open(): Argument #1 ($uri) cannot be empty');
        }
        $reader = VmXmlReader::open($ctx, $uri, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($reader): void {
            if (null === $reader) {
                $ret->bool(false);
            } else {
                $ret->object($reader);
            }
        });
    }
}
