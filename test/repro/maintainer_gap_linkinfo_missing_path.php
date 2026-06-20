<?php

// Maintainer gap / issue #10294 — linkinfo() missing path returns -1 not false (ext/standard/link.c).
var_export(linkinfo('/no/such/phpc-linkinfo-missing-path'));
echo "\n";
var_export(linkinfo('/no/such/phpc-linkinfo-missing-path') === -1);
echo "\n";
