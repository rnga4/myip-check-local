FROM php:8.2-fpm-alpine

WORKDIR /app
COPY index.php admin.php track.php api.php config.php theme-popup.php pl-komatsu-ui-template.css theme.js .

EXPOSE 9000
