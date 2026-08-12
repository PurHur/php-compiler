--TEST--
AOT locale_set_default(null) TypeError (#29932)
--SKIPIF--
<?php
if (!\function_exists('locale_set_default')) {
    echo 'skip ext/intl locale_set_default not available';
}
?>
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
  var_export(locale_set_default(null));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
  var_export(Locale::setDefault(null));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: locale_set_default(): Argument #1 ($locale) must be of type string, null given
TypeError: Locale::setDefault(): Argument #1 ($locale) must be of type string, null given
