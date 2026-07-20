--TEST--
NumberFormatter SPELLOUT/ORDINAL/DURATION/PATTERN_RULEBASED format (#21110)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$spell = new NumberFormatter('en', NumberFormatter::SPELLOUT);
echo 'spellout_en=', $spell->format(42), "\n";
$spellFr = new NumberFormatter('fr', NumberFormatter::SPELLOUT);
echo 'spellout_fr=', $spellFr->format(42), "\n";

$ord = new NumberFormatter('en', NumberFormatter::ORDINAL);
echo 'ordinal_en=', $ord->format(42), "\n";
$ordFr = new NumberFormatter('fr', NumberFormatter::ORDINAL);
echo 'ordinal_fr=', $ordFr->format(42), "\n";

$dur = new NumberFormatter('en', NumberFormatter::DURATION);
echo 'duration_en_42=', $dur->format(42), "\n";
echo 'duration_en_3661=', $dur->format(3661), "\n";

$pat = $ord->getPattern();
echo 'ordinal_pattern_prefix=', substr($pat, 0, strlen('%digits-ordinal')), "\n";
$rbnf = new NumberFormatter('en', NumberFormatter::PATTERN_RULEBASED, $pat);
echo 'rulebased_ordinal=', $rbnf->format(42), "\n";

$proc = numfmt_create('en', NumberFormatter::SPELLOUT);
echo 'proc_spellout=', numfmt_format($proc, 42), "\n";
?>
--EXPECT--
spellout_en=forty-two
spellout_fr=quarante-deux
ordinal_en=42nd
ordinal_fr=42e
duration_en_42=42 sec.
duration_en_3661=1:01:01
ordinal_pattern_prefix=%digits-ordinal
rulebased_ordinal=42nd
proc_spellout=forty-two
