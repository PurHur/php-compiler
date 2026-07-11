--TEST--
stdlib gettext '_' alias — function_exists('_') + fallback translation (#14966)
--FILE--
<?php
if (!extension_loaded('gettext')) {
    echo "gettext-missing\n";
    return;
}
echo function_exists('gettext') ? "gettext-ok\n" : "gettext-missing-fn\n";
echo function_exists('_') ? "underscore-ok\n" : "underscore-missing\n";
if (function_exists('_')) {
    echo _('hello'), "\n";
}
--EXPECT--
gettext-ok
underscore-ok
hello

