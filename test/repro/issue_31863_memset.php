<?php
// #31863 — user-script gethostname still works after LibcExtern always-on memset drop
// (NestedJIT JitGethostnameKernel zeros its buffer via module-local ensureMemsetDecl).
$host = gethostname();
echo is_string($host) && $host !== '' ? 'ok' : 'fail';
echo PHP_EOL;
