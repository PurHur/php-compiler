<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * SPL class registry for spl_classes() (php-src ext/spl/php_spl.c SPL_LIST_CLASSES; issue #11802).
 */
final class VmSplRegistry
{
    /**
     * php-src SPL_LIST_CLASSES order — classes and interfaces registered by ext/spl.
     *
     * @var list<string>
     */
    private const REGISTRY = [
        'ArrayObject',
        'ArrayIterator',
        'SplDoublyLinkedList',
        'EmptyIterator',
        'RecursiveArrayIterator',
        'RecursiveCallbackFilterIterator',
        'RecursiveIterator',
        'OuterIterator',
        'SplObserver',
        'SplSubject',
    ];

    /**
     * @return array<string, string> class name => class name (Zend spl_classes wire)
     */
    public static function classesMap(Context $ctx): array
    {
        $result = [];
        foreach (self::REGISTRY as $name) {
            if (isset($ctx->classes[strtolower($name)])) {
                $result[$name] = $name;
            }
        }

        return $result;
    }

    public static function classesVariable(Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classesMap($ctx) as $name => $value) {
            $var = new Variable();
            $var->string($value);
            $ht->add($name, $var);
        }

        return $result;
    }
}
