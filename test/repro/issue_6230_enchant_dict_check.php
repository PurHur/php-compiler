<?php

declare(strict_types=1);

/**
 * Repro for #6230 — enchant_broker_init / EnchantBroker / enchant_dict_check.
 *
 * Requires libenchant-2 + en_US dictionary (Docker image packs both).
 */
echo 'exists=', (int) function_exists('enchant_broker_init'), PHP_EOL;
echo 'ce=', (int) class_exists('EnchantBroker'), PHP_EOL;

$broker = enchant_broker_init();
echo 'init=', (int) (false !== $broker), PHP_EOL;
if (false === $broker) {
    echo "fail_init\n";
    return;
}
echo 'class=', (int) ($broker instanceof EnchantBroker), PHP_EOL;

if (!enchant_broker_dict_exists($broker, 'en_US')) {
    echo "no_dict\n";
    enchant_broker_free($broker);
    return;
}

$dict = enchant_broker_request_dict($broker, 'en_US');
echo 'dict=', (int) (false !== $dict), PHP_EOL;
if (false === $dict) {
    echo "fail_dict\n";
    enchant_broker_free($broker);
    return;
}
echo 'dict_class=', (int) ($dict instanceof EnchantDictionary), PHP_EOL;

echo 'test=', (int) enchant_dict_check($dict, 'test'), PHP_EOL;
echo 'tset=', (int) enchant_dict_check($dict, 'tset'), PHP_EOL;

$sugg = enchant_dict_suggest($dict, 'tset');
echo 'sugg=', (int) (is_array($sugg) && count($sugg) > 0), PHP_EOL;

enchant_broker_free_dict($dict);
enchant_broker_free($broker);
echo "ok\n";
