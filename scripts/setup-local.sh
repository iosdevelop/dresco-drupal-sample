#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ADMIN_USER="${FRESCO_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${FRESCO_ADMIN_PASSWORD:-admin}"
ADMIN_EMAIL="${FRESCO_ADMIN_EMAIL:-admin@example.com}"
SITE_NAME="${FRESCO_SITE_NAME:-Dresco}"

cd "${PROJECT_ROOT}"

if ! command -v ddev >/dev/null 2>&1; then
  echo "DDEV is required: https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/" >&2
  exit 1
fi

if [[ ! -f .ddev/config.yaml ]]; then
  echo "Missing .ddev/config.yaml. Run this script from the dresco-drupal repository." >&2
  exit 1
fi

echo "Starting the Dresco DDEV environment..."
ddev start

echo "Installing Composer dependencies from composer.lock..."
ddev composer install --no-interaction

if [[ "$(ddev drush status --field=bootstrap --format=string 2>/dev/null || true)" != "Successful" ]]; then
  echo "Installing Drupal..."
  ddev drush site:install standard \
    --site-name="${SITE_NAME}" \
    --account-name="${ADMIN_USER}" \
    --account-pass="${ADMIN_PASSWORD}" \
    --account-mail="${ADMIN_EMAIL}" \
    --site-mail="${ADMIN_EMAIL}" \
    -y
else
  echo "Drupal is already installed; preserving the existing database."
fi

echo "Enabling Dresco modules and theme..."
ddev drush en fresco_recipe fresco_ai fresco_pages fresco_migrate -y
ddev drush theme:enable fresco_theme -y
ddev drush config:set system.theme default fresco_theme -y
ddev drush updatedb -y
ddev drush cache:rebuild

OLLAMA_MODEL="${FRESCO_OLLAMA_MODEL:-qwen2.5:3b}"
OLLAMA_VISION_MODEL="${FRESCO_OLLAMA_VISION_MODEL:-moondream:latest}"
echo "Pulling Ollama models (${OLLAMA_MODEL}, ${OLLAMA_VISION_MODEL}); the first run downloads a few GB..."
ddev exec -s ollama ollama pull "${OLLAMA_MODEL}"
ddev exec -s ollama ollama pull "${OLLAMA_VISION_MODEL}"
ddev drush config:set fresco_ai.settings ollama_base_url http://ollama:11434 -y
ddev drush config:set fresco_ai.settings ollama_model "${OLLAMA_MODEL}" -y
ddev drush config:set fresco_ai.settings ollama_vision_model "${OLLAMA_VISION_MODEL}" -y
ddev drush config:set fresco_ai.settings provider ollama -y

PROJECT_URL="$(ddev describe -j | php -r '
  $data = json_decode(stream_get_contents(STDIN), true);
  echo $data["raw"]["primary_url"] ?? "https://dresco-drupal.ddev.site";
')"

echo
echo "Dresco is ready at ${PROJECT_URL}"
echo "Local administrator: ${ADMIN_USER}"
if [[ "${ADMIN_PASSWORD}" == "admin" ]]; then
  echo "Local password: admin (development only; change it before sharing this environment)"
else
  echo "Local password: the value supplied through FRESCO_ADMIN_PASSWORD"
fi

