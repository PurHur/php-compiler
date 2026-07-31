<?php
/**
 * Enum cases that differ only by case must both be allowed
 * (Zend/zend_enum.c / zend_compile.c — case-sensitive names).
 *
 *   php test/repro/maintainer_gap_enum_case_case_differ_declare.php
 *   php bin/vm.php test/repro/maintainer_gap_enum_case_case_differ_declare.php
 *   # Zend: A a / diff
 *   # VM (broken): Fatal Cannot redefine class constant E::a
 */
enum E
{
    case A;
    case a;
}
foreach (E::cases() as $c) {
    echo $c->name, ' ';
}
echo "\n";
echo E::A === E::a ? "same\n" : "diff\n";
