#!/bin/bash
set -euo pipefail
read -r command backend frontend extra <<< "${SSH_ORIGINAL_COMMAND:-}"
[[ $command == deploy && $backend =~ ^[0-9a-f]{40}$ && $frontend =~ ^[0-9a-f]{40}$ && -z ${extra:-} ]] || exit 64
exec sudo -n /usr/local/sbin/school-deploy "$backend" "$frontend"
