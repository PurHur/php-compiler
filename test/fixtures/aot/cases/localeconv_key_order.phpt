--TEST--
AOT: localeconv() key order grouping/mon_grouping last (#28154; ext/standard/locale.c)
--FILE--
<?php
$keys = array_keys(localeconv());
echo implode(',', $keys), "\n";
--EXPECT--
decimal_point,thousands_sep,int_curr_symbol,currency_symbol,mon_decimal_point,mon_thousands_sep,positive_sign,negative_sign,int_frac_digits,frac_digits,p_cs_precedes,p_sep_by_space,n_cs_precedes,n_sep_by_space,p_sign_posn,n_sign_posn,grouping,mon_grouping
