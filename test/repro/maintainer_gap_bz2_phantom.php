<?php
declare(strict_types=1);
/**
 * #25011 — ext/bz2 must not phantom under PROFILE=8.4 when host Zend lacks it.
 */
echo 'extension_loaded(bz2)=', var_export(extension_loaded('bz2'), true), "\n";
echo 'function_exists(bzcompress)=', var_export(function_exists('bzcompress'), true), "\n";
