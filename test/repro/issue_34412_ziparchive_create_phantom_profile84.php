<?php

declare(strict_types=1);

/**
 * AOT must not phantom-seed ZipArchive::CREATE when zip is withheld (#34412 / #18137).
 *
 * Run with PHP_COMPILER_PROFILE=8.4 and PHP_COMPILER_ENABLE_ZIP unset.
 * Pre-#34412 AOT printed `1` while VM said class not found.
 */
echo 'V=', ZipArchive::CREATE, PHP_EOL;
