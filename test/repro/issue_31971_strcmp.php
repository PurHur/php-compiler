<?php
// #31971 — user-script strcmp() still works after LibcExtern always-on
// strcmp drop (NestedJIT leaves use module-local ensureStrcmpDecl).
echo strcmp('abc', 'abd');
echo PHP_EOL;
echo strcmp('abc', 'abc');
echo PHP_EOL;
echo strcmp('abd', 'abc');
echo PHP_EOL;
