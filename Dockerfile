FROM ideasdigitales/laravel-phpenv:8.4-fpm

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN groupadd --gid ${GROUP_ID} developer && \
    useradd -u ${USER_ID} -g developer -s /bin/bash --home /home/developer developer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize

RUN chown -R developer:developer /var/www/html && \
    chmod -R 775 storage bootstrap/cache

USER developer

EXPOSE 9000
