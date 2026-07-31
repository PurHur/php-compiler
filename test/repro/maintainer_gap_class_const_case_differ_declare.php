<?php
/**
 * Class constants that differ only by case must both be allowed
 * (Zend/zend_compile.c — case-sensitive declare; related fetch fix #25910).
 *
 *   php test/repro/maintainer_gap_class_const_case_differ_declare.php
 *   php bin/vm.php test/repro/maintainer_gap_class_const_case_differ_declare.php
 *   # Zend: 1 2
 *   # VM (broken): Fatal Cannot redefine class constant C::a
 */
class C
{
    public const A = 1;
    public const a = 2;
}
echo C::A, ' ', C::a, "\n";
