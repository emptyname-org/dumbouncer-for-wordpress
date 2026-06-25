#!/usr/bin/env bash
# Layer 4: integration on/off toggles. With an integration OFF, Dumbouncer must
# be transparent - no marker on the page, and a blind (no-proof) submit goes
# straight through. With it ON, the marker is back and the gate enforces.
# Needs wp-cli (WP_PATH / WPCLI from config.env).
DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "$DIR/config.env" ] || { echo "no config.env - run setup.sh first"; exit 1; }
. "$DIR/config.env"
WPCLI="${WPCLI:-wp}"
[ -n "${WP_PATH:-}" ] || { echo "SKIP toggles: WP_PATH not in config.env (wp-cli needed)"; exit 0; }
wp() { $WPCLI --path="$WP_PATH" "$@"; }
UA='Mozilla/5.0 (compatible; DumbouncerTest/1.0)'
pass=0; fail=0
ck() { if [ "$2" = 1 ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1  ($3)"; fail=$((fail+1)); fi; }
# count the dumbouncer marker on a page, cache-busted so we see the live render
markers() { curl -s -A "$UA" "$1&dbo_cb=$RANDOM$RANDOM" 2>/dev/null | grep -c 'name="dumbouncer_gate"'; }

declare -A PAGE=(
  [comments]="$BASE_URL/?p=$COMMENT_POST"
  [cf7]="$BASE_URL/?page_id=$CF7_PAGE"
  [wpforms]="$BASE_URL/?page_id=$WPF_PAGE"
  [login]="$BASE_URL/wp-login.php?dbo=1"
  [register]="$BASE_URL/wp-login.php?action=register"
)

for k in comments cf7 wpforms login register; do
  if [ "$k" = wpforms ] && [ -z "${WPF_PAGE:-}" ]; then echo "SKIP  wpforms toggle (WPForms not configured)"; continue; fi
  wp option update "dumbouncer_int_$k" '' >/dev/null 2>&1
  m=$(markers "${PAGE[$k]}"); ck "$k OFF -> no marker on page" "$([ "$m" = 0 ] && echo 1 || echo 0)" "markers=$m"
  wp option update "dumbouncer_int_$k" 1 >/dev/null 2>&1
  m=$(markers "${PAGE[$k]}"); ck "$k ON  -> marker present"   "$([ "$m" -ge 1 ] && echo 1 || echo 0)" "markers=$m"
done

# Behavioural off/on for comments (no solving needed to demonstrate transparency).
CP="$BASE_URL/wp-comments-post.php"
post_comment() { curl -s "$@" -A "$UA" --data "comment_post_ID=$COMMENT_POST&author=T&email=t@example.com&comment=toggle+$RANDOM$RANDOM" "$CP"; }
wp option update dumbouncer_int_comments '' >/dev/null 2>&1
code=$(post_comment -o /dev/null -w '%{http_code}')
ck "comments OFF -> blind comment posts (302), gate transparent" "$([ "$code" = 302 ] && echo 1 || echo 0)" "http=$code"
wp option update dumbouncer_int_comments 1 >/dev/null 2>&1
body=$(post_comment -s)
if echo "$body" | grep -q "less than"; then ck "comments ON -> blind comment blocked (prose challenge)" 1; else ck "comments ON -> blind comment blocked (prose challenge)" 0 "no challenge"; fi

# Restore the suite default (everything ON, as setup.sh leaves it).
for k in comments cf7 wpforms login register; do wp option update "dumbouncer_int_$k" 1 >/dev/null 2>&1; done

echo
echo "toggles: $pass passed, $fail failed"
[ "$fail" = 0 ]
