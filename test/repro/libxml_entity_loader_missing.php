<?php

declare(strict_types=1);

echo 'libxml_disable_entity_loader: '.(function_exists('libxml_disable_entity_loader') ? 'yes' : 'no')."\n";
echo 'libxml_set_external_entity_loader: '.(function_exists('libxml_set_external_entity_loader') ? 'yes' : 'no')."\n";
echo 'libxml_get_external_entity_loader: '.(function_exists('libxml_get_external_entity_loader') ? 'yes' : 'no')."\n";

$prev = libxml_disable_entity_loader();
echo 'disable_default_prev: '.(false === $prev ? 'false' : 'true')."\n";
$prev2 = libxml_disable_entity_loader(true);
echo 'disable_true_prev: '.(false === $prev2 ? 'false' : 'true')."\n";
libxml_disable_entity_loader(false);

echo 'get_loader_default: '.(null === libxml_get_external_entity_loader() ? 'null' : 'set')."\n";

$ok = libxml_set_external_entity_loader('strlen');
echo 'set_loader: '.($ok ? 'true' : 'false')."\n";
$loader = libxml_get_external_entity_loader();
echo 'get_loader_after_set: '.(\is_callable($loader) ? 'callable' : 'not')."\n";

$ok2 = libxml_set_external_entity_loader(null);
echo 'clear_loader: '.($ok2 ? 'true' : 'false')."\n";
echo 'get_loader_after_clear: '.(null === libxml_get_external_entity_loader() ? 'null' : 'set')."\n";

echo "ok\n";
