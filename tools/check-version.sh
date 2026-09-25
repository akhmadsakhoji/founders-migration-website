#!/bin/sh
# Checks that the plugin header, FMWP_VERSION and readme.txt's Stable tag agree,
# and, given a tag (v1.2.3), that they match it. Used by CI and the release workflow.
set -eu
cd "$(dirname "$0")/.."
header=$(sed -n 's/^ \* Version: *//p' founders-migration-website.php | tr -d '\r')
constant=$(sed -n "s/^define( 'FMWP_VERSION', '\([^']*\)' );/\1/p" constants.php | tr -d '\r')
stable=$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '\r')
echo "header=$header constant=$constant stable_tag=$stable"
[ -n "$header" ] && [ "$header" = "$constant" ] && [ "$header" = "$stable" ] || { echo "Versions differ." >&2; exit 1; }
sed -n '/^== Changelog ==/,/^== /p' readme.txt | grep -qxF "= $header =" || { echo "readme.txt has no changelog entry for $header." >&2; exit 1; }
grep -qF "## [$header] - " CHANGELOG.md || { echo "CHANGELOG.md has no section for $header." >&2; exit 1; }
if [ $# -gt 0 ]; then
	[ "${1#v}" = "$header" ] || { echo "Tag $1 does not match version $header." >&2; exit 1; }
fi
