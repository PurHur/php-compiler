--TEST--
AOT: object vs string/null ordered compare must verify and match Zend (#32514 leftover of #32503)
--FILE--
<?php
error_reporting(E_ALL & ~E_NOTICE);
echo (new stdClass() > "a") ? "s_gt\n" : "s_ngt\n";
echo (new stdClass() <=> "a"), "\n";
echo (new stdClass() <=> "10"), "\n";
echo ("a" > new stdClass()) ? "rs_gt\n" : "rs_ngt\n";
echo (new stdClass() > null) ? "n_gt\n" : "n_ngt\n";
echo (new stdClass() <=> null), "\n";
echo (null > new stdClass()) ? "rn_gt\n" : "rn_ngt\n";
class T32514
{
    public function __toString()
    {
        return "b";
    }
}
echo (new T32514() <=> "a"), "\n";
echo (new T32514() <=> "b"), "\n";
echo (new T32514() <=> "c"), "\n";
--EXPECT--
s_gt
1
1
rs_ngt
n_gt
1
rn_ngt
1
0
-1
--EXPECT_EXIT--
0
