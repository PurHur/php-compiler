<?php
// AOT mktime/gmmktime full-arity — fold + civil IR runtime (#33934).
echo mktime(0, 0, 0, 1, 1, 2020), "\n";
function mk($h, $i, $s, $m, $d, $y)
{
    return mktime($h, $i, $s, $m, $d, $y);
}
echo mk(0, 0, 0, 1, 1, 2020), "\n";
echo gmmktime(0, 0, 0, 1, 1, 2020), "\n";
function gmk($h, $i, $s, $m, $d, $y)
{
    return gmmktime($h, $i, $s, $m, $d, $y);
}
echo gmk(0, 0, 0, 1, 1, 2020), "\n";
