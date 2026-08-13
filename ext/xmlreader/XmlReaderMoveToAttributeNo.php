<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::moveToAttributeNo() — attribute cursor by index (#19939). */
final class XmlReaderMoveToAttributeNo extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('moveToAttributeNo');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::moveToAttributeNo', 1);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::moveToAttributeNo()'
        );
        $index = self::coerceIndex($frame->calledArgs[1]->resolveIndirect());
        $ok = VmXmlReader::moveToAttributeNo($entry, $index);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    /** Weak int coercion matching zend_parse_parameters("l") / php-src-strict. */
    private static function coerceIndex(Variable $var): int
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
                throw new \TypeError('XMLReader::moveToAttributeNo(): Argument #1 ($index) must be of type int, string given');
            default:
                $label = Variable::TYPE_ARRAY === $var->type ? 'array' : (
                    Variable::TYPE_OBJECT === $var->type ? 'object' : 'unknown'
                );
                throw new \TypeError(sprintf(
                    'XMLReader::moveToAttributeNo(): Argument #1 ($index) must be of type int, %s given',
                    $label
                ));
        }
    }
}
