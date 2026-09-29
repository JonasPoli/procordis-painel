#!/bin/bash
set -e

# PHP a usar (ex.: PHP=/RunCloud/Packages/php83rc/bin/php ./build.sh)
PHP="${PHP:-php}"

echo "==> Limpando assets compilados e caches anteriores..."
rm -rf public/assets
rm -f var/tailwind/*.css
"$PHP" bin/console cache:clear

echo "==> Compilando Tailwind CSS (minificado)..."
"$PHP" bin/console tailwind:build --minify

echo "==> Compilando AssetMapper..."
"$PHP" bin/console asset-map:compile

echo "==> Build concluído com sucesso!"
