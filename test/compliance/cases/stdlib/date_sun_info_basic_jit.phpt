--TEST--
stdlib date_sun_info() JIT/AOT — compile-time baked literals (#6831)
--FILE--
<?php
$info = date_sun_info(1718121600, 48.8566, 2.3522);
echo $info['sunrise'], "\n";
echo $info['sunset'], "\n";
echo $info['transit'], "\n";
--EXPECT--
1718077492
1718135752
1718106622
