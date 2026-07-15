--TEST--
AOT: xmlrpc_encode() compile-time scalar literal (#19048, ext/xmlrpc/xmlrpc.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsXmlrpc()) {
    die('skip xmlrpc withheld on reference profile (#18503)');
}
--FILE--
<?php
declare(strict_types=1);

echo xmlrpc_encode(42);
--EXPECT--
<?xml version="1.0" encoding="UTF-8"?>
<param>
<value><int>42</int></value>
</param>
--EXPECT_EXIT--
0
