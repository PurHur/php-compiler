<?php
/**
 * Issue #20613 — enchant broker list/describe + dict session round-trip.
 */
declare(strict_types=1);

foreach ([
    'enchant_broker_list_dicts',
    'enchant_broker_describe',
    'enchant_dict_add',
    'enchant_dict_is_added',
    'enchant_dict_describe',
] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', "\n";
}

$broker = enchant_broker_init();
if (false === $broker) {
    echo "fail_init\n";
    exit(0);
}
if (!enchant_broker_dict_exists($broker, 'en_US')) {
    echo "no_dict\n";
    exit(0);
}
$dicts = enchant_broker_list_dicts($broker);
echo 'dicts=', count($dicts) > 0 ? 'Y' : 'N', "\n";
$providers = enchant_broker_describe($broker);
echo 'providers=', count($providers) > 0 ? 'Y' : 'N', "\n";
$dict = enchant_broker_request_dict($broker, 'en_US');
$word = 'xyzzyenchant20613';
enchant_dict_add_to_session($dict, $word);
echo 'added=', enchant_dict_is_added($dict, $word) ? 'Y' : 'N', "\n";
$info = enchant_dict_describe($dict);
echo 'lang=', $info['lang'] ?? '?', "\n";
enchant_broker_free_dict($dict);
enchant_broker_free($broker);
echo "ok\n";
