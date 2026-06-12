<?php
// Repro for #7230 — RequestMethod enum (ext/standard/basic_functions.stub.php).

echo 'RequestMethod enum: ', enum_exists('RequestMethod', false) ? 'yes' : 'no', "\n";
if (!enum_exists('RequestMethod', false)) {
    fwrite(STDERR, "FAIL: RequestMethod enum missing\n");
    exit(1);
}
echo 'backed enum: ', enum_exists('RequestMethod', false) && !unitenum_exists('RequestMethod') ? 'yes' : 'no', "\n";
echo 'Post: ', RequestMethod::Post->name, '=', RequestMethod::Post->value, "\n";
echo 'Get: ', RequestMethod::Get->name, '=', RequestMethod::Get->value, "\n";
