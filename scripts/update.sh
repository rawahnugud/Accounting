#!/usr/bin/env bash
# Pull the latest release and run the upgrade, keeping the PHP handler block cPanel adds to public/.htaccess.
# The braces make bash read the whole script before git pull can change this file.
{
set -euo pipefail
cd "$(dirname "$0")/.."
file=public/.htaccess
start='^# php -- BEGIN cPanel-generated handler'
end='^# php -- END cPanel-generated handler'
block=$(sed -n "/$start/,/$end/p" "$file")
if ! sed "/$start/,/$end/d" "$file" | diff -B -q - <(git show HEAD:"$file") >/dev/null; then
    echo "$file has changes besides the cPanel PHP handler block. Review them with git diff first."; exit 1
fi
# Put the handler back even if the pull fails, so the site keeps its PHP version.
restore() { if [ -n "$block" ] && ! grep -q "$start" "$file"; then printf '\n%s\n' "$block" >> "$file"; fi; }
trap restore EXIT
git checkout -- "$file"
git pull --ff-only origin "$(git rev-parse --abbrev-ref HEAD)"
restore
bash scripts/upgrade-office.sh
exit
}
