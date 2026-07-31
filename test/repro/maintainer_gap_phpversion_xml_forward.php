<?php
declare(strict_types=1);

/**
 * Repro for #25819 — phpversion('xml') must equal phpversion() on the reference profile
 * (not CompilerVersion::VERSION / 8.4.0-dev).
 */
$core = phpversion();
echo "phpversion=", $core, "\n";
echo "xml=", phpversion('xml'), "\n";
echo "libxml=", phpversion('libxml'), "\n";
echo "simplexml=", phpversion('simplexml'), "\n";
echo "xmlreader=", phpversion('xmlreader'), "\n";
echo "xmlwriter=", phpversion('xmlwriter'), "\n";
echo phpversion('xml') === $core ? "PASS\n" : "FAIL\n";
