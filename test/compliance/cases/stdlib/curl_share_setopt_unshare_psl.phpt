--TEST--
curl_share_setopt() UNSHARE+CURL_LOCK_DATA_PSL → false / Unknown share option (#27704)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

$sh = curl_share_init();

$okShare = curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_PSL);
echo 'share_psl=', var_export($okShare, true), ' errno=', curl_share_errno($sh), "\n";

$okCookie = curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
echo 'share_cookie=', var_export($okCookie, true), "\n";

$okUnshareCookie = curl_share_setopt($sh, CURLSHOPT_UNSHARE, CURL_LOCK_DATA_COOKIE);
echo 'unshare_cookie=', var_export($okUnshareCookie, true), ' errno=', curl_share_errno($sh), "\n";

$ok = curl_share_setopt($sh, CURLSHOPT_UNSHARE, CURL_LOCK_DATA_PSL);
$e = curl_share_errno($sh);
echo 'unshare_psl=', var_export($ok, true), ' errno=', $e, ' str=', curl_share_strerror($e), "\n";

$r = new ReflectionFunction('curl_share_setopt');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
}
echo 'return:', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";

curl_share_close($sh);
?>
--EXPECT--
share_psl=true errno=0
share_cookie=true
unshare_cookie=true errno=0
unshare_psl=false errno=1 str=Unknown share option
share_handle:CurlShareHandle
option:int
value:mixed
return:bool
