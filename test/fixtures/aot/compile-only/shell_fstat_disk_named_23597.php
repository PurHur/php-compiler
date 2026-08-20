<?php
// AOT lint: shell_exec + disk_* named stub params (#23597).
// fstat AOT IR verify and fpassthru thin-AOT link are pre-existing gaps (positional too).
echo shell_exec(command: 'true'), "\n";
echo disk_free_space(directory: '/'), "\n";
echo disk_total_space(directory: '/'), "\n";
