#!/usr/bin/env bash
set -eu
cd "$(dirname "$0")/.."
IFS= read -rs -p 'New administrator password: ' secret </dev/tty
echo
IFS= read -rs -p 'Confirm password: ' confirm </dev/tty
echo
[[ "$secret" == "$confirm" ]] || { echo 'Passwords differ.'; exit 1; }
printf '%s' "$secret" | php artisan staff:promote "${1:?Supply the existing staff email}"
