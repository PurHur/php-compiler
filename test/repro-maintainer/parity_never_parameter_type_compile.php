<?php

/** Issue #11473 — standalone never parameter must fatal at compile (Zend/zend_compile.c). */

function acceptsNever(never $value): void {}

echo "compiled\n";
