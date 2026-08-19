<?php
/**
 * #32514 leftover of #32503 — object vs string / null ordered compare.
 * php-src: Zend/zend_operators.c compare_function
 * Plain object vs string (no __toString): object is greater.
 * null literals are TYPE_VALUE isNullConstant, not TYPE_NULL.
 */
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
