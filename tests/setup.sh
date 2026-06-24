#!/usr/bin/env bash
# Provision a WordPress site for the Dumbouncer test suite (idempotent).
# Usage:  WPCLI="php /path/wp-cli.phar"  bash tests/setup.sh /path/to/wordpress http://127.0.0.1:8088
#   - WPCLI defaults to "wp"; set it if you use a wp-cli phar.
#   - ADMIN_USER / ADMIN_PASS override the admin creds used for login tests.
set -u
WPPATH="${1:?usage: setup.sh /path/to/wordpress BASE_URL}"
BASE="${2:?usage: setup.sh /path/to/wordpress BASE_URL}"
WPCLI="${WPCLI:-wp}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin12345}"
DIR="$(cd "$(dirname "$0")" && pwd)"
wp() { $WPCLI --path="$WPPATH" "$@"; }

wp plugin activate dumbouncer >/dev/null 2>&1
for k in comments cf7 wpforms login register; do wp option update "dumbouncer_int_$k" 1 >/dev/null 2>&1; done
wp option update users_can_register 1 >/dev/null 2>&1
wp option update comment_registration 0 >/dev/null 2>&1
wp rewrite structure '' >/dev/null 2>&1

# --- Contact Form 7 ---
wp plugin install contact-form-7 --activate >/dev/null 2>&1
CF7_ID=$(wp post list --post_type=wpcf7_contact_form --field=ID 2>/dev/null | head -1)
HASH=$(wp post meta get "$CF7_ID" _hash 2>/dev/null)
CF7_PAGE=$(wp post list --post_type=page --name=dbo-cf7 --field=ID 2>/dev/null | head -1)
[ -z "$CF7_PAGE" ] && CF7_PAGE=$(wp post create --post_type=page --post_status=publish --post_title="DBO CF7" --post_name=dbo-cf7 --post_content="[contact-form-7 id=\"$HASH\"]" --porcelain 2>/dev/null)

# --- WPForms (optional: needs curl + dom PHP extensions) ---
wp plugin install wpforms-lite --activate >/dev/null 2>&1
WPF_OK=$(wp eval 'echo class_exists("WPForms\\WPForms")?"1":"";' 2>/dev/null)
WPF_ID=""; WPF_PAGE=""
if [ -n "$WPF_OK" ]; then
  WPF_ID=$(wp post list --post_type=wpforms --field=ID 2>/dev/null | head -1)
  if [ -z "$WPF_ID" ]; then
    WPF_ID=$(wp post create --post_type=wpforms --post_status=publish --post_title="DBO WPF" --porcelain 2>/dev/null)
    JSON='{"id":"'$WPF_ID'","fields":{"0":{"id":"0","type":"name","label":"Name","format":"simple","required":"1"},"1":{"id":"1","type":"email","label":"Email","required":"1"},"2":{"id":"2","type":"textarea","label":"Message","required":"1"}},"settings":{"form_title":"DBO WPF","submit_text":"Submit","ajax_submit":"1","antispam":"0","antispam_v3":"0","honeypot":"0"},"meta":{"template":"simple-contact-form-template"}}'
    wp post update "$WPF_ID" --post_content="$JSON" >/dev/null 2>&1
  fi
  WPF_PAGE=$(wp post list --post_type=page --name=dbo-wpf --field=ID 2>/dev/null | head -1)
  [ -z "$WPF_PAGE" ] && WPF_PAGE=$(wp post create --post_type=page --post_status=publish --post_title="DBO WPF" --post_name=dbo-wpf --post_content="[wpforms id=\"$WPF_ID\"]" --porcelain 2>/dev/null)
fi

# --- Comments: open on the default post ---
COMMENT_POST=$(wp post list --post_type=post --posts_per_page=1 --field=ID 2>/dev/null | head -1); [ -z "$COMMENT_POST" ] && COMMENT_POST=1
wp post update "$COMMENT_POST" --comment_status=open >/dev/null 2>&1

cat > "$DIR/config.env" <<EOF
BASE_URL="$BASE"
CF7_FORM_ID="$CF7_ID"
CF7_PAGE="$CF7_PAGE"
WPF_FORM_ID="$WPF_ID"
WPF_PAGE="$WPF_PAGE"
COMMENT_POST="$COMMENT_POST"
ADMIN_USER="$ADMIN_USER"
ADMIN_PASS="$ADMIN_PASS"
EOF
echo "wrote $DIR/config.env:"; cat "$DIR/config.env"
if [ -z "$WPF_OK" ]; then
  echo "NOTE: WPForms did not load (needs curl + dom PHP extensions) - its tests will SKIP."
fi
exit 0
