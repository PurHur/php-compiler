<?php

/**
 * #30781 — mb_ereg_search_init/mb_ereg_search + mb_regex_encoding AOT fold.
 */
mb_ereg_search_init('hello world', 'world');
echo mb_ereg_search() ? 'yes' : 'no', "\n";
echo 'ok', "\n";

$enc = mb_regex_encoding();
echo 'enc=', $enc, "\n";
echo 'set=', mb_regex_encoding('UTF-8') ? 'true' : 'false', "\n";
echo 'enc2=', mb_regex_encoding(), "\n";
