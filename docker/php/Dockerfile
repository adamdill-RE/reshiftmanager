# Local development only. Nothing here ships — the server runs LiteSpeed with
# LSAPI, and the app deploys by file copy with no build step (CLAUDE.md).
#
# Apache stands in for LiteSpeed because it reads the same .htaccess syntax.
# What matters is that the PHP version and limits match production, so a
# constraint that will bite there bites here first.
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite expires headers

# Production has no OPcache, so leave it disabled here too: code changes take
# effect on the next request, and slow-path performance is honest.
COPY php.ini /usr/local/etc/php/conf.d/zz-resm.ini
COPY apache.conf /etc/apache2/conf-available/resm.conf
RUN a2enconf resm

# Mirrors the server: DOCUMENT_ROOT is the parent of the app's directory, and
# the application code lives in a sibling of the document root rather than
# inside it. Landing on the host root redirects to the app.
RUN printf '%s\n' '<?php header("Location: /resm/", true, 302);' > /var/www/html/index.php
