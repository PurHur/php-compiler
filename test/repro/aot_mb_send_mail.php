<?php
/** AOT leftover: mb_send_mail() still LogicException while capabilities advertise AOT yes (#6548). */
var_export(function_exists('mb_send_mail'));
echo "\n";
var_export(@mb_send_mail('user@example.com', 'Subject', "Body\n"));
echo "\n";
