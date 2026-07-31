--TEST--
get_headers/http_response_code/stream_socket_pair/headers_sent/flush/ob_*/getmxrr Reflection stubs (#25780)
--FILE--
<?php
foreach (['get_headers', 'http_response_code', 'stream_socket_pair', 'headers_sent', 'flush', 'ob_get_status', 'ob_list_handlers', 'getmxrr'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $d = $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : ($p->isOptional() ? 'OPT' : 'REQ');
        $ps[] = ($p->isPassedByReference() ? '&' : '').$p->getName().':'.$t.'='.$d;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : '-';
    echo $fn, ' => (', $ret, ') ', implode(', ', $ps), "\n";
}
?>
--EXPECT--
get_headers => (array|false) url:string=REQ, associative:bool=false, context:-=null
http_response_code => (int|bool) response_code:int=0
stream_socket_pair => (array|false) domain:int=REQ, type:int=REQ, protocol:int=REQ
headers_sent => (bool) &filename:-=null, &line:-=null
flush => (void) 
ob_get_status => (array) full_status:bool=false
ob_list_handlers => (array) 
getmxrr => (bool) hostname:string=REQ, &hosts:-=REQ, &weights:-=null
