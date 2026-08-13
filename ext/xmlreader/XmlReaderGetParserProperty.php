<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::getParserProperty() — php-src zim_XMLReader_getParserProperty (#19553). */
final class XmlReaderGetParserProperty extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('getParserProperty');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::getParserProperty', 1);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmXmlReader::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError('XMLReader::getParserProperty(): Argument must be XMLReader, '.$entry->class->name.' given');
        }
        $property = self::coerceInt($frame->calledArgs[1]->resolveIndirect());
        $value = VmXmlReader::getParserProperty($entry, $property);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($value): void {
            $ret->bool($value);
        });
    }

    private static function coerceInt(Variable $var): int
    {
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_NULL:
                return 0;
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if (is_numeric($s)) {
                    return (int) (float) $s;
                }
                throw new \TypeError('XMLReader::getParserProperty(): Argument #1 ($property) must be of type int, string given');
            default:
                $label = Variable::TYPE_ARRAY === $var->type ? 'array' : (
                    Variable::TYPE_OBJECT === $var->type ? 'object' : 'unknown'
                );
                throw new \TypeError(sprintf(
                    'XMLReader::getParserProperty(): Argument #1 ($property) must be of type int, %s given',
                    $label
                ));
        }
    }
}
