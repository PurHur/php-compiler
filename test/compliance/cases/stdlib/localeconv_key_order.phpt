--TEST--
stdlib localeconv() key order — grouping/mon_grouping after n_sign_posn (#28154, ext/standard/locale.c)
--FILE--
<?php
$keys = array_keys(localeconv());
echo implode(',', $keys), "\n";
$g = array_search('grouping', $keys, true);
$mg = array_search('mon_grouping', $keys, true);
$nsp = array_search('n_sign_posn', $keys, true);
echo ($g !== false && $mg !== false && $nsp !== false
    && $nsp < $g && $g < $mg
    && $keys[count($keys) - 2] === 'grouping'
    && $keys[count($keys) - 1] === 'mon_grouping') ? "order_ok\n" : "order_bad\n";
--EXPECT--
decimal_point,thousands_sep,int_curr_symbol,currency_symbol,mon_decimal_point,mon_thousands_sep,positive_sign,negative_sign,int_frac_digits,frac_digits,p_cs_precedes,p_sep_by_space,n_cs_precedes,n_sep_by_space,p_sign_posn,n_sign_posn,grouping,mon_grouping
order_ok
