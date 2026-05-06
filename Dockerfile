FROM dunglas/frankenphp:1.12.2-php8.4-alpine

ARG USER=developer
ARG USER_ID=1000

RUN adduser -u ${USER_ID} -D ${USER} || true; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    mkdir -p /config/caddy /data/caddy; \
    chown -R ${USER}:${USER} /config/caddy /data/caddy

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN install-php-extensions \
	pdo_pgsql \
	gd \
	intl \
	zip \
  bcmath \
	xdebug

USER ${USER}
