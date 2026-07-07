--TEST--
stdlib gettimeofday/sleep/usleep/http_response_code/setcookie JIT named params (issue #17092)
--FILE--
<?php
$f = gettimeofday(as_float: true);
$sl = sleep(seconds: 0);
usleep(microseconds: 0);
$http = http_response_code(response_code: 200);
$cookie = setcookie(name: 'c', value: 'v');
echo is_float($f) ? "gt_float\n" : "gt_bad\n";
echo $sl === 0 ? "sleep_ok\n" : "sleep_bad\n";
echo "usleep_ok\n";
echo $http ? "http_ok\n" : "http_bad\n";
echo $cookie ? "cookie_ok\n" : "cookie_bad\n";
--EXPECT--
gt_float
sleep_ok
usleep_ok
http_ok
cookie_ok
