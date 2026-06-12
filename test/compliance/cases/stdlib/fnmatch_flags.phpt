--TEST--
stdlib fnmatch() FNM_* flags parity (#4400, ext/standard/file.c)
--FILE--
<?php
// CASEFOLD
var_dump(fnmatch('*.TXT', 'a.txt'));
var_dump(fnmatch('*.TXT', 'a.txt', FNM_CASEFOLD));

// PERIOD
var_dump(fnmatch('*', '.hidden', FNM_PERIOD));
var_dump(fnmatch('.*', '.hidden', FNM_PERIOD));

// PATHNAME
var_dump(fnmatch('*', 'a/b', FNM_PATHNAME));
var_dump(fnmatch('*/b', 'a/b', FNM_PATHNAME));

// NOESCAPE
var_dump(fnmatch('a\\*b', 'a*b'));
var_dump(fnmatch('a\\*b', 'a*b', FNM_NOESCAPE));
--EXPECT--
bool(false)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
