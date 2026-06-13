<?php
echo function_exists('stream_notification_callback') ? "yes\n" : "no\n";
var_export(stream_notification_callback(null));
echo "\n";
var_export(stream_notification_callback(null));
echo "\n";
