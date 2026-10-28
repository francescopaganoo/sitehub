# Sito statico (Coolify: build pack "Dockerfile", porta 80)
FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY . /usr/share/nginx/html/
# la configurazione e i file di SiteHub (template, copia degli articoli) non devono essere pubblici
RUN rm -rf /usr/share/nginx/html/nginx.conf /usr/share/nginx/html/_sitehub
