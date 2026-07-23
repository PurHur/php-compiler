<?php
// Repro #22251 — enchant_dict_is_in_session aliases is_added (php-src since 8.0)
echo 'is_in_session=', function_exists('enchant_dict_is_in_session') ? 'Y' : 'N', "\n";
echo 'is_added=', function_exists('enchant_dict_is_added') ? 'Y' : 'N', "\n";
if (!function_exists('enchant_broker_init')) {
    echo "skip_no_enchant\n";
    return;
}
$b = enchant_broker_init();
if (!enchant_broker_dict_exists($b, 'en_US')) {
    echo "skip_no_dict\n";
    enchant_broker_free($b);
    return;
}
$d = enchant_broker_request_dict($b, 'en_US');
$word = 'phpcompilerxyz'.getmypid();
echo 'before=', enchant_dict_is_in_session($d, $word) ? 'Y' : 'N', "\n";
enchant_dict_add_to_session($d, $word);
echo 'after_session=', enchant_dict_is_in_session($d, $word) ? 'Y' : 'N', "\n";
echo 'after_is_added=', enchant_dict_is_added($d, $word) ? 'Y' : 'N', "\n";
enchant_broker_free_dict($d);
enchant_broker_free($b);
