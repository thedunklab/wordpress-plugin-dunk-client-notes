#!/usr/bin/env bash
# Avvia il WordPress di test e, al primo giro, lo installa con il plugin attivo.
set -euo pipefail
cd "$(dirname "$0")"

PORT="${WP_PORT:-8095}"
wp() { docker compose run --rm -T cli wp "$@" 2> >(grep -v ' Container ' >&2); }

docker compose up -d

echo "Attendo WordPress..."
until docker compose run --rm -T cli test -f wp-config.php 2>/dev/null; do sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url="http://localhost:${PORT}" --title="DCN Test" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
  wp language core install it_IT --activate || true
  wp rewrite structure '/%postname%/' --hard
  wp option update dcn_enabled 1
  wp option update dcn_label "DCN Test"
  for t in "Chi siamo" "Servizi" "Contatti"; do
    wp post create --post_type=page --post_status=publish --post_title="$t" \
      --post_content="<!-- wp:paragraph --><p>Pagina di prova per le note: $t.</p><!-- /wp:paragraph -->"
  done
fi

wp plugin activate dunk-client-notes >/dev/null
echo
echo "Sito:    http://localhost:${PORT}"
echo "Bacheca: http://localhost:${PORT}/wp-admin  (credenziali in dev/README.md)"
echo "Email:   http://localhost:${MAIL_PORT:-8035}"
