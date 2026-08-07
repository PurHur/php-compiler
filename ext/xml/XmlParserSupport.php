<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * XMLParser object lifecycle (php-src ext/xml/xml.c; issue #18163).
 *
 * PHP 8+: xml_parser_create() returns XMLParser objects, not integer resources.
 */
final class XmlParserSupport
{
    public const CLASS_LC = 'xmlparser';

    public const CLASS_NAME = 'XMLParser';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        // php-src ext/xml/xml.stub.php — `final class XMLParser` (PHP 8+; #28386).
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function createParser(Context $ctx, bool $nsAware = false, string $nsSeparator = ':'): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('XMLParser is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        VmXml::initParserState($entry->id, $nsAware, $nsSeparator);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function requireParser(
        Variable $var,
        string $function,
        int $argNum,
        string $paramName = 'parser'
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                self::CLASS_NAME,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                self::CLASS_NAME,
                VmStreamArg::debugTypeName($var)
            ));
        }

        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                self::CLASS_NAME,
                $object->class->name
            ));
        }
        if (!VmXml::hasParserState($object->id)) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be a valid XML parser',
                $function,
                $argNum,
                $paramName
            ));
        }

        return $object;
    }
}
