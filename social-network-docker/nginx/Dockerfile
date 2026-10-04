FROM nginx:1.27.5-alpine

ARG SITE_CONFIG=native.conf

COPY nginx.conf /etc/nginx/nginx.conf
COPY ${SITE_CONFIG} /etc/nginx/conf.d/default.conf
