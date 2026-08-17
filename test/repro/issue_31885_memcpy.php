<?php
// #31885 — user-script strtok still works after LibcExtern always-on memcpy drop
// (NestedJIT StringStrtokJit copies token bytes via module-local ensureMemcpyDecl).
echo strtok('hello world', ' ');
echo PHP_EOL;
