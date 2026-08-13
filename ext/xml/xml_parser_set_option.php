<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/** xml_parser_set_option() — configure parser (php-src ext/xml/xml.c; #18203, #30652). */
final class xml_parser_set_option extends XmlFunction
{
    public function __construct()
    {
        parent::__construct('xml_parser_set_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError('xml_parser_set_option() expects exactly 3 arguments, '.$argc.' given');
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parser_set_option', 1);
        $optionArg = $frame->calledArgs[1]->resolveIndirect();
        $option = Variable::TYPE_INTEGER === $optionArg->type ? $optionArg->toInt() : (int) $optionArg->toString();
        $valueArg = $frame->calledArgs[2]->resolveIndirect();
        // php-src 8.3+ xml.c: zpp "Olz" then E_WARNING if not string|int|bool; still apply + RETURN_TRUE (#30652).
        // Withheld on 8.4.0-dev reference / unset PROFILE (Zend 8.2 is silent).
        if (
            self::warnsOnNonScalarOptionValue()
            && Variable::TYPE_STRING !== $valueArg->type
            && Variable::TYPE_INTEGER !== $valueArg->type
            && Variable::TYPE_BOOLEAN !== $valueArg->type
        ) {
            $given = EnumCaseSupport::typeNameForVariable($valueArg);
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    'xml_parser_set_option(): Argument #3 ($value) must be of type string|int|bool, '.$given.' given',
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
        }
        $value = match ($valueArg->type) {
            Variable::TYPE_STRING => $valueArg->toString(),
            Variable::TYPE_INTEGER => $valueArg->toInt(),
            Variable::TYPE_BOOLEAN => $valueArg->toBool(),
            Variable::TYPE_NULL => null,
            default => $valueArg->toString(),
        };
        $ok = XmlParserHandlers::setOption($parser, $option, $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    /**
     * php-src 8.3+ type warning on $value (ext/xml/xml.c). Silent on Zend 8.2 / reference profile.
     */
    private static function warnsOnNonScalarOptionValue(): bool
    {
        if (version_compare(CompilerVersion::VERSION, '8.3', '<')) {
            return false;
        }
        if (version_compare(CompilerVersion::VERSION, '8.4.0', '>=')) {
            return true;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(CompilerVersion::languageProfileVersion(), '8.3.0', '>=');
    }
}
