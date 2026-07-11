--TEST--
stdlib LIBXML_NOENT and LIBXML_DTDLOAD parse flags (ext/libxml/libxml.c, #11885)
--FILE--
<?php
echo defined('LIBXML_NOENT') ? constant('LIBXML_NOENT') : 'undef', "\n";
echo defined('LIBXML_DTDLOAD') ? LIBXML_DTDLOAD : 'undef', "\n";
echo defined('LIBXML_DTDATTR') ? LIBXML_DTDATTR : 'undef', "\n";
echo defined('LIBXML_DTDVALID') ? LIBXML_DTDVALID : 'undef', "\n";
--EXPECT--
2
4
8
16
