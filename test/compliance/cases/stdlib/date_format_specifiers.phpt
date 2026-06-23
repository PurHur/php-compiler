--TEST--
stdlib date()/gmdate() format specifiers at epoch UTC (#10857, ext/standard/datetime.c)
--FILE--
<?php
date_default_timezone_set('UTC');
$ts = 0;
foreach (['U', 'u', 'c', 'r', 'e', 'T', 'Z', 'O', 'P', 'w', 'N', 'z', 't', 'L', 'W', 'g', 'h', 'a', 'A', 'S', 'n', 'j', 'G', 'y'] as $c) {
    echo $c, '=', date($c, $ts), "\n";
}
echo 'escape=', date('\\\\U', $ts), "\n";
echo 'gmdate_U=', gmdate('U', $ts), "\n";
--EXPECT--
U=0
u=000000
c=1970-01-01T00:00:00+00:00
r=Thu, 01 Jan 1970 00:00:00 +0000
e=UTC
T=UTC
Z=0
O=+0000
P=+00:00
w=4
N=4
z=0
t=31
L=0
W=01
g=12
h=12
a=am
A=AM
S=st
n=1
j=1
G=0
y=70
escape=\0
gmdate_U=0
