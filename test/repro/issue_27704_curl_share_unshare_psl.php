<?php
declare(strict_types=1);

// Repro for #27704 — CURLSHOPT_UNSHARE + CURL_LOCK_DATA_PSL must fail like Zend/libcurl.
$sh = curl_share_init();
$ok = curl_share_setopt($sh, CURLSHOPT_UNSHARE, CURL_LOCK_DATA_PSL);
$e = curl_share_errno($sh);
echo 'ok=', var_export($ok, true), ' errno=', $e, ' str=', curl_share_strerror($e), "\n";
$r = new ReflectionFunction('curl_share_setopt');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
}
echo 'return:', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
