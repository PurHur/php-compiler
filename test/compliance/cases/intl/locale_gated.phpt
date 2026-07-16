--TEST--
intl Locale class advertised without extension_loaded('intl') (#6696; supersedes #16214 gate for Locale only)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('intl'), "\n";
echo 'fn=', (int) function_exists('locale_get_default'), "\n";
echo 'set_fn=', (int) function_exists('locale_set_default'), "\n";
echo 'class=', (int) class_exists('Locale', false), "\n";
echo 'default_len=', (strlen(Locale::getDefault()) > 0) ? 'yes' : 'no', "\n";
--EXPECT--
loaded=0
fn=1
set_fn=1
class=1
default_len=yes
