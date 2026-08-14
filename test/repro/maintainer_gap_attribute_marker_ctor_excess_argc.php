<?php
// Repro #31089 — built-in attribute marker __construct excess argc (Zend/zend_attributes.c)

function probe(string $label, callable $fn): void
{
    try {
        $fn();
        echo "$label SILENT\n";
    } catch (Throwable $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}

probe('Attribute', fn () => new Attribute(1, 1));
probe('AllowDynamicProperties', fn () => new AllowDynamicProperties(1));
probe('ReturnTypeWillChange', fn () => new ReturnTypeWillChange(1));
probe('SensitiveParameter', fn () => new SensitiveParameter(1));
