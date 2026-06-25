<?php
$user = get_current_user();
echo is_string($user) && '' !== $user ? "user\n" : "bad_user\n";
