--TEST--
stdlib LIBXML_VERSION / LIBXML_DOTTED_VERSION / LIBXML_LOADED_VERSION (#24051, ext/libxml/libxml.c)
--FILE--
<?php
echo defined('LIBXML_VERSION') ? gettype(constant('LIBXML_VERSION')) : 'undef', "\n";
echo defined('LIBXML_DOTTED_VERSION') ? gettype(constant('LIBXML_DOTTED_VERSION')) : 'undef', "\n";
echo defined('LIBXML_LOADED_VERSION') ? gettype(constant('LIBXML_LOADED_VERSION')) : 'undef', "\n";
echo is_int(LIBXML_VERSION) && LIBXML_VERSION > 0 ? 'version_ok' : 'version_bad', "\n";
echo LIBXML_LOADED_VERSION === (string) LIBXML_VERSION ? 'loaded_ok' : 'loaded_bad', "\n";
echo preg_match('/^\d+\.\d+(\.\d+)?/', LIBXML_DOTTED_VERSION) ? 'dotted_ok' : 'dotted_bad', "\n";
echo defined('LIBXML_NOERROR') && LIBXML_NOERROR === 32 ? 'flags_ok' : 'flags_bad', "\n";
--EXPECT--
integer
string
string
version_ok
loaded_ok
dotted_ok
flags_ok
