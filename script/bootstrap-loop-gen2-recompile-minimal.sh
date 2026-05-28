#!/usr/bin/env bash
# Alias for gen-2→gen-3 spine recompile (Makefile: bootstrap-loop-gen2-recompile-minimal).
exec "$(dirname "$0")/bootstrap-loop-gen2-recompile-spine.sh" "$@"
