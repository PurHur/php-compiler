--TEST--
AOT: goto / labels — forward and backward jumps (issue #4042)
--FILE--
<?php
$i = 0;
start:
$i++;
if ($i < 3) {
    goto start;
}
echo $i, "\n";

if (false) {
    skip:
}
echo "ok\n";

goto end;
echo "no\n";
end:
echo "done\n";
--EXPECT--
3
ok
done
