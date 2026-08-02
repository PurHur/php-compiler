<?php
$ctx = stream_context_create();
$ok = stream_context_set_option($ctx, "http", "timeout", 1.0);
$opts = stream_context_get_options($ctx);
echo "ok=", $ok ? "true" : "false", "\n";
echo "isset=", isset($opts["http"]["timeout"]) ? "yes" : "no", "\n";
if (isset($opts["http"]["timeout"])) {
    echo "timeout=", $opts["http"]["timeout"], "\n";
}
// batch 2-arg form
$ctx2 = stream_context_create();
$ok2 = stream_context_set_option($ctx2, ["http" => ["timeout" => 2.0]]);
$opts2 = stream_context_get_options($ctx2);
echo "batch_ok=", $ok2 ? "true" : "false", "\n";
echo "batch_isset=", isset($opts2["http"]["timeout"]) ? "yes" : "no", "\n";
