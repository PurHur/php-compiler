<?php

// Compile-only (#5304): zend_version() JIT/AOT lowering via __compiler_zend_version.
$v = zend_version();
echo strlen($v) > 0 ? "ok\n" : "fail\n";
