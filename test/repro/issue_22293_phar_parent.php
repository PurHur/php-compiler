<?php
/**
 * Issue #22293 — Phar extends RecursiveDirectoryIterator (SplFileInfo APIs).
 */
declare(strict_types=1);

var_export(get_parent_class(Phar::class));
echo PHP_EOL;
var_export(method_exists(Phar::class, 'getFilename'));
echo PHP_EOL;
