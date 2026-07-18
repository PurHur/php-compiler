<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMNodeList / DOMNamedNodeMap engine dimension handlers (php-src ext/dom/php_dom.c; #20311).
 *
 * Classic collections use zend_object_handlers.read_dimension / has_dimension — not ArrayAccess.
 * Writes remain Error "Cannot use object of type … as array".
 */
final class VmDomCollectionDimension
{
    /** C INT_MAX bound used by php-src NamedNodeMap::item / read_dimension. */
    private const INT_MAX_32 = 2147483647;

    public static function isCollection(ObjectEntry $object): bool
    {
        return VmDom::isNodeList($object) || VmDom::isNamedNodeMap($object);
    }

    /**
     * Mirror dom_nodemap_or_nodelist_process_offset_as_named — true when offset is a non-numeric string.
     *
     * @param-out int $lval
     */
    public static function processOffsetAsNamed(Variable $offset, ?int &$lval): bool
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_STRING === $offset->type) {
            $str = $offset->toString();
            if (!self::tryParseNumericString($str, $lval)) {
                return true;
            }

            return false;
        }
        $lval = $offset->toInt();

        return false;
    }

    public static function hasDimension(ObjectEntry $object, Variable $offset): bool
    {
        $lval = 0;
        if (self::processOffsetAsNamed($offset, $lval)) {
            if (VmDom::isNamedNodeMap($object)) {
                return null !== VmDom::namedNodeMapGetNamedItem($object, $offset->resolveIndirect()->toString());
            }
            // Dom\HTMLCollection string offset → namedItem (php-src html_collection.c; #20709).
            if (VmDom::isHtmlCollection($object)) {
                return null !== VmDom::htmlCollectionNamedItem($object, $offset->resolveIndirect()->toString());
            }

            return false;
        }
        if (VmDom::isNodeList($object)) {
            return $lval >= 0 && $lval < VmDom::nodeListCount($object);
        }
        if ($lval < 0 || $lval > self::INT_MAX_32) {
            return false;
        }

        return $lval < \count(DomRegistry::state($object)->listNodeIds);
    }

    /**
     * Read $collection[$offset] into $out (object or null). Throws ValueError for NamedNodeMap OOB int.
     */
    public static function readDimension(ObjectEntry $object, Variable $offset, Variable $out): void
    {
        $lval = 0;
        if (self::processOffsetAsNamed($offset, $lval)) {
            if (VmDom::isNamedNodeMap($object)) {
                $node = VmDom::namedNodeMapGetNamedItem($object, $offset->resolveIndirect()->toString());
                if (null === $node) {
                    $out->null();

                    return;
                }
                $out->object($node);

                return;
            }
            if (VmDom::isHtmlCollection($object)) {
                $node = VmDom::htmlCollectionNamedItem($object, $offset->resolveIndirect()->toString());
                if (null === $node) {
                    $out->null();

                    return;
                }
                $out->object($node);

                return;
            }
            $out->null();

            return;
        }

        if (VmDom::isNodeList($object)) {
            if ($lval < 0) {
                $out->null();

                return;
            }
            $node = VmDom::nodeListItem($object, $lval);
            if (null === $node) {
                $out->null();

                return;
            }
            $out->object($node);

            return;
        }

        // DOMNamedNodeMap int index — php-src ValueError outside 0..INT_MAX.
        if ($lval < 0 || $lval > self::INT_MAX_32) {
            throw new \ValueError('must be between 0 and '.self::INT_MAX_32);
        }
        $node = VmDom::namedNodeMapItem($object, $lval);
        if (null === $node) {
            $out->null();

            return;
        }
        $out->object($node);
    }

    /**
     * @param-out int $lval
     */
    private static function tryParseNumericString(string $str, ?int &$lval): bool
    {
        if ('' === $str) {
            return false;
        }
        // zend is_numeric_string(allow_errors=true): reject non-numeric entirely.
        if (!is_numeric($str)) {
            return false;
        }
        if (preg_match('/^[+-]?\d+$/', $str) === 1) {
            $lval = (int) $str;

            return true;
        }
        $lval = (int) (float) $str;

        return true;
    }
}
