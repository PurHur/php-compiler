--TEST--
AOT collator_create(null) TypeError (#29933)
--SKIPIF--
<?php
if (!\extension_loaded('intl') || !\function_exists('collator_create')) {
    echo 'skip ext/intl Collator not available';
}
?>
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
  var_export(collator_create(null));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: collator_create(): Argument #1 ($locale) must be of type string, null given
