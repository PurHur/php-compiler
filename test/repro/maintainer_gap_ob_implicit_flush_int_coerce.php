<?php

declare(strict_types=1);

// Zend parity: ob_implicit_flush() coerces int 0/1 to bool (ext/standard/output.c).
ob_implicit_flush(1);
ob_implicit_flush(0);
ob_implicit_flush(true);
echo "ok\n";
