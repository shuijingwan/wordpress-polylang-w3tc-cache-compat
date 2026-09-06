#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
fixture_root="$(mktemp -d)"
trap 'rm -rf "$fixture_root"' EXIT

cache_root="$fixture_root/page_enhanced"
snapshot_dir="$fixture_root/snapshots"
www_dir="$cache_root/www.shuijingwanwq.com/example"
en_dir="$cache_root/en.shuijingwanwq.com/example"
mkdir -p "$www_dir" "$en_dir"

printf 'www' > "$www_dir/_index_slash_ssl.html"
printf 'gzip' > "$www_dir/_index_slash_ssl.html_gzip"
printf 'old' > "$www_dir/_index_slash_ssl.html_old"
printf 'en' > "$en_dir/_index_slash_ssl.html"

PAGE_CACHE_ROOT="$cache_root" SNAPSHOT_DIR="$snapshot_dir" \
	"$project_root/scripts/page-cache-snapshot" 'before/test label' > "$fixture_root/before.out"

before_snapshot="$(awk -F= '/^SNAPSHOT_FILE=/{print $2}' "$fixture_root/before.out")"
grep -q '^ACTIVE_HTML_COUNT=1$' "$before_snapshot"
grep -q '^OLD_COUNT=1$' "$before_snapshot"
grep -q '^READ_ONLY_CACHE_INSPECTION=yes$' "$before_snapshot"

printf 'en2' > "$en_dir/second_index_ssl.html"
PAGE_CACHE_ROOT="$cache_root" SNAPSHOT_DIR="$snapshot_dir" \
	"$project_root/scripts/page-cache-snapshot" after > "$fixture_root/after.out"
after_snapshot="$(awk -F= '/^SNAPSHOT_FILE=/{print $2}' "$fixture_root/after.out")"

PAGE_CACHE_ROOT="$cache_root" SNAPSHOT_DIR="$snapshot_dir" \
	"$project_root/scripts/page-cache-snapshot" diff "$before_snapshot" "$after_snapshot" > "$fixture_root/diff.out"
grep -q '^HOST=www.shuijingwanwq.com$' "$fixture_root/diff.out"
grep -q '^HOST=en.shuijingwanwq.com$' "$fixture_root/diff.out"

echo OK
