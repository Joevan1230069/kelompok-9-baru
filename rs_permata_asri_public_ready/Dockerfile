FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork rewrite

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["bash", "-lc", "a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true; a2enmod mpm_prefork >/dev/null 2>&1 || true; exec apache2-foreground"] 
