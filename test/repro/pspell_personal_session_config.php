<?php
// Repro #22229 — pspell personal/session/config surface
$fns = [
    'pspell_new',
    'pspell_add_to_personal',
    'pspell_add_to_session',
    'pspell_clear_session',
    'pspell_save_wordlist',
    'pspell_store_replacement',
    'pspell_config_create',
    'pspell_config_mode',
    'pspell_new_personal',
    'pspell_new_config',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
if (!function_exists('pspell_new')) {
    echo "skip_no_pspell\n";
    return;
}
$dict = @pspell_new('en');
if (false === $dict) {
    echo "skip_no_dict\n";
    return;
}
echo 'session_add=', pspell_add_to_session($dict, 'phpcompilerxyz') ? 'Y' : 'N', "\n";
echo 'session_check=', pspell_check($dict, 'phpcompilerxyz') ? 'Y' : 'N', "\n";
echo 'clear=', pspell_clear_session($dict) ? 'Y' : 'N', "\n";
$cfg = pspell_config_create('en');
echo 'cfg=', $cfg instanceof PSpell\Config ? 'Y' : 'N', "\n";
pspell_config_mode($cfg, PSPELL_FAST);
$fromCfg = pspell_new_config($cfg);
echo 'from_cfg=', $fromCfg instanceof PSpell\Dictionary ? 'Y' : 'N', "\n";
$tmp = sys_get_temp_dir().'/pspell22229_'.getmypid().'.pws';
@unlink($tmp);
file_put_contents($tmp, "personal_ws-1.1 en 0\n");
$pers = @pspell_new_personal($tmp, 'en');
echo 'personal=', $pers instanceof PSpell\Dictionary ? 'Y' : 'N', "\n";
@unlink($tmp);
