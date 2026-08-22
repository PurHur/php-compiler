<?php
session_start(["name" => "PHPCSSO", "use_cookies" => 0]);
echo session_name(), "|", session_status(), "\n";
