# Sito statico (Coolify: build pack "Dockerfile", porta 80)
FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY . /usr/share/nginx/html/
# la configurazione e i file di SiteHub (template, copia degli articoli) non devono essere pubblici
RUN rm -rf /usr/share/nginx/html/nginx.conf /usr/share/nginx/html/_sitehub
# impronte di style.css e script.js (come versione.sh): CSS e JS con la versione giusta restano in cache un anno (nginx.conf)
RUN cd /usr/share/nginx/html \
 && css=$(sha1sum style.css 2>/dev/null | cut -c1-8) \
 && js=$(sha1sum script.js 2>/dev/null | cut -c1-8) \
 && sed -i "s/VERSIONE_CSS/${css:-nessuna}/; s/VERSIONE_JS/${js:-nessuna}/" /etc/nginx/conf.d/default.conf
