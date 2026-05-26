<?php

declare(strict_types=1);

/**
 * North Star 2 self-host presenter (issue #2207).
 *
 * Documents bootstrap M0–M4 gates — does not run make targets from PHP (v1).
 *
 * VM:
 *   ./phpc run examples/008-SelfHostProbe/example.php
 *
 * Full ladder:
 *   make north-star2-verify
 *   BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke
 *
 * Harness:
 *   ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./phpc run examples/008-SelfHostProbe/example.php'
 */
echo "SelfHostProbe — North Star 2 smoke (#2207, epic #1492)\n\n";

echo "Milestone | What green looks like\n";
echo "----------|----------------------\n";
echo "M0 link   | make bootstrap-selfhost-link\n";
echo "M2 spine  | BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke (609/611 units)\n";
echo "M3 strict | BOOTSTRAP_M3_HELLOWORLD_STRICT=1 ./script/bootstrap-selfhost-helloworld-probe.sh (opt-in)\n";
echo "M4 loop   | BOOTSTRAP_LOOP_PROBE_GATE=1 ./script/bootstrap-loop-probe.sh --dry-run (opt-in)\n\n";

echo "Presenter bundle (copy-paste):\n";
echo "  make north-star2-verify\n";
echo "  BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke\n\n";

echo "Inventory + doctor:\n";
echo "  php script/bootstrap-inventory.php --check\n";
echo "  ./phpc doctor --selfhost\n";
echo "  ./phpc doctor --gates | grep -i bootstrap\n\n";

echo "Harness-safe Docker:\n";
echo "  ./script/docker-exec.sh -- bash -lc 'make north-star2-verify'\n\n";

echo "Next spine slices: #2201 (bin/vm.php link+execute), #2134 (deferred paths).\n";
echo "Compiler unit probe: #2216. Docs: docs/bootstrap-selfhost.md, docs/self-host-target.md\n";
