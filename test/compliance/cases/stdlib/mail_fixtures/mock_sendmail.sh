#!/bin/sh
# Capture sendmail stdin for mail() compliance (#3285).
out="$(dirname "$0")/mock_sendmail.last"
cat > "$out"
exit 0
