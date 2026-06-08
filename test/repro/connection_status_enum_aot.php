<?php
echo connection_status()->value, "\n";
echo connection_status() === ConnectionStatus::Normal ? "match\n" : "bad\n";
