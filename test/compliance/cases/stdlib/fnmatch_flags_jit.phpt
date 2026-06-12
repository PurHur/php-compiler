--TEST--
stdlib fnmatch() FNM_* flags JIT (#4400)
--FILE--
<?php
var_dump(fnmatch('*.TXT', 'a.txt', FNM_CASEFOLD));
var_dump(fnmatch('*', '.hidden', FNM_PERIOD));
var_dump(fnmatch('*/b', 'a/b', FNM_PATHNAME));
var_dump(fnmatch('a\\*b', 'a*b', FNM_NOESCAPE));
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
