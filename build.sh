#!/bin/bash
set -e

echo "==> Limpando assets compilados e caches anteriores..."
rm -rf public/assets
rm -f var/tailwind/*.css
php bin/console cache:clear

echo "==> Compilando Tailwind CSS (minificado)..."
php bin/console tailwind:build --minify

echo "==> Compilando AssetMapper..."
php bin/console asset-map:compile

echo "==> Build concluído com sucesso!"
