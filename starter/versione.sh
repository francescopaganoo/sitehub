#!/bin/sh
# Aggiorna la versione di style.css e script.js nelle pagine (style.css?v=…).
# Da lanciare dopo ogni modifica a CSS o JS, prima del commit: così browser e Cloudflare
# scaricano subito i file nuovi invece di usare quelli in cache.
cd "$(dirname "$0")"
css=$(shasum style.css | cut -c1-8)
js=$(shasum script.js | cut -c1-8)
find . -name '*.html' -not -path './.git/*' | while read -r f; do
  sed -i.bak -E "s#/style\.css(\?v=[a-f0-9]+)?\"#/style.css?v=$css\"#; s#/script\.js(\?v=[a-f0-9]+)?\"#/script.js?v=$js\"#" "$f" && rm "$f.bak"
done
echo "style.css?v=$css  script.js?v=$js"
