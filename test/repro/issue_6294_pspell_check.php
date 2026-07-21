<?php
// Repro #6294 — pspell_new / pspell_check / pspell_suggest (ext/pspell)
echo 'exists=', function_exists('pspell_new') ? 'Y' : 'N', PHP_EOL;
if (!function_exists('pspell_new')) {
    echo "missing\n";
    exit(0);
}
echo 'ext=', extension_loaded('pspell') ? 'Y' : 'N', PHP_EOL;
$dict = @pspell_new('en');
if ($dict === false) {
    echo "connect-fail\n";
    exit(0);
}
echo 'ce=', ($dict instanceof PSpell\Dictionary) ? 'Y' : 'N', PHP_EOL;
echo 'colour=', pspell_check($dict, 'colour') ? '1' : '0', PHP_EOL;
echo 'colourr=', pspell_check($dict, 'colourr') ? '1' : '0', PHP_EOL;
$sugs = pspell_suggest($dict, 'colourr');
echo 'sugs=', is_array($sugs) ? count($sugs) : 'F', PHP_EOL;
echo "ok\n";
