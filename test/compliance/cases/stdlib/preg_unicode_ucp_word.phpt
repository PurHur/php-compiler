--TEST--
stdlib preg /u \\w Unicode + \\p{L} (issue #22003, ext/pcre / VmPregEngine)
--FILE--
<?php
foreach (['a', 'é', 'ü', '1', '_', ' '] as $ch) {
    echo $ch, ' w=', (int) preg_match('/\w/u', $ch),
        ' W=', (int) preg_match('/\W/u', $ch),
        ' L=', (int) preg_match('/\p{L}/u', $ch),
        "\n";
}
echo 'pL=', (int) preg_match('/\pL/u', 'é'), "\n";
echo 'PL=', (int) preg_match('/\P{L}/u', '1'), "\n";
echo 'ascii_w=', (int) preg_match('/\w/', 'é'), "\n";
--EXPECT--
a w=1 W=0 L=1
é w=1 W=0 L=1
ü w=1 W=0 L=1
1 w=1 W=0 L=0
_ w=1 W=0 L=0
  w=0 W=1 L=0
pL=1
PL=1
ascii_w=0
