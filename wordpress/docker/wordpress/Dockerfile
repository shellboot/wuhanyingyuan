FROM wordpress:7.1.4-php8.3-fpm-alpine

COPY docker/wordpress/php.ini /usr/local/etc/php/conf.d/wuhanyingyuan.ini
COPY docker/wordpress/yy-entrypoint.sh /usr/local/bin/wuhanyingyuan-entrypoint
COPY docker/wordpress/yy-uploads-switch.sh /usr/local/bin/yy-uploads-switch
COPY wp-content/themes/wuhanyingyuan /var/www/html/wp-content/themes/wuhanyingyuan
COPY wp-content/plugins/wuhanyingyuan-core /var/www/html/wp-content/plugins/wuhanyingyuan-core

RUN chmod 755 /usr/local/bin/wuhanyingyuan-entrypoint /usr/local/bin/yy-uploads-switch \
    && chown -R www-data:www-data /var/www/html/wp-content/themes/wuhanyingyuan \
    /var/www/html/wp-content/plugins/wuhanyingyuan-core

ENTRYPOINT ["wuhanyingyuan-entrypoint"]
CMD ["php-fpm"]
