<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmFsWritePure;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\PathSupport;
use PHPCompiler\VM\Variable;

/**
 * SimpleXMLElement::asXML / saveXML — serialize node tree
 * (php-src ext/simplexml/sxe.c zim_SimpleXMLElement_asXML + saveXML FALIAS; #18038, #19413, #22006).
 */
final class SimpleXmlElementAsXml extends VmClassMethod
{
    public function __construct(string $name = 'asXML')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $label = 'SimpleXMLElement::'.$this->name.'()';
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException($label.' called without $this');
        }
        // php-src simplexml.stub.php: asXML(?string $filename = null) / saveXML FALIAS (#30828).
        $this->requireAtMostUserArgCount($frame, 'SimpleXMLElement::'.$this->name, 1);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            $label
        );
        if (null === $frame->returnVar) {
            return;
        }
        $includeDeclaration = SimpleXmlRegistry::documentKey($entry) === $entry->id
            && !SimpleXmlRegistry::isView($entry)
            && !SimpleXmlRegistry::isAttributesView($entry);
        $xml = VmSimpleXml::asXml($entry, $includeDeclaration);
        if (false === $xml) {
            $frame->returnVar->bool(false);

            return;
        }

        $filename = self::optionalFilename($frame, $label);
        if (null === $filename) {
            $frame->returnVar->string($xml);

            return;
        }

        $written = self::writeXmlFile($filename, $xml);
        if (false === $written) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'SimpleXMLElement::'.$this->name, $filename);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(true);
    }

    /**
     * Optional filename operand — null / absent means return the XML string (php-src Z_PARAM_PATH_OPTIONAL).
     *
     * Empty path → ValueError "Path cannot be empty" (php-src zend_parse_arg_path; #29268, #30457).
     */
    private static function optionalFilename(Frame $frame, string $label): ?string
    {
        if (!isset($frame->calledArgs[1])) {
            return null;
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        // Method arg index is 0 for the filename (php-src Argument #1 ($filename)).
        $path = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            rtrim($label, '()'),
            0,
            'filename',
            'string',
            false
        );
        if ('' === $path) {
            throw new \ValueError(PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
        }

        return $path;
    }

    /** @return int|false bytes written, or false on failure */
    private static function writeXmlFile(string $filename, string $xml): int|false
    {
        if (VmFsWritePure::available()) {
            return VmFsWritePure::write($filename, $xml);
        }

        return @\file_put_contents($filename, $xml);
    }
}
