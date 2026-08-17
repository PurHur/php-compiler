<?php
// #31954 — user-script str_contains/strpos still work after LibcExtern always-on
// memcmp drop (NestedJIT VmStringCompare uses module-local ensureMemcmpDecl).
echo str_contains('hello world', 'world') ? 'yes' : 'no';
echo PHP_EOL;
echo strpos('hello world', 'lo');
echo PHP_EOL;
