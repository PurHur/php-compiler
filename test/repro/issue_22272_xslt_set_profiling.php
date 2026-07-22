<?php
/** Repro #22272 — XSLTProcessor::setProfiling() */
$p = new XSLTProcessor();
echo method_exists($p, 'setProfiling') ? 'Y' : 'N', PHP_EOL;
var_export($p->setProfiling('/tmp/phpc-xslt-profile-22272-repro.txt'));
echo PHP_EOL;
var_export($p->setProfiling(null));
echo PHP_EOL;
