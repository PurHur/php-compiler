<?php
/**
 * Maintainer gap: "$this" / "{$this}" interpolated outside object context.
 * Zend: Error "Using $this when not in object context"
 * VM: yields empty string (no Error)
 *
 * Note: bare `$this` as an expression already Errors on VM (matches Zend).
 * Arrow/closure `$this` outside object was fixed in #10558 / #28814.
 */
error_reporting(E_ALL);

echo 'dq: ';
try {
    $s = "$this";
    echo 'OK ' . var_export($s, true) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo 'curly: ';
try {
    $s = "{$this}";
    echo 'OK ' . var_export($s, true) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo 'heredoc: ';
try {
    $s = <<<TXT
{$this}
TXT;
    echo 'OK ' . var_export($s, true) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
