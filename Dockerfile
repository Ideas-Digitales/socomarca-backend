FROM ideasdigitales/laravel-phpenv:8.4-fpm

ARG USER_ID
ARG GROUP_ID

RUN groupadd --gid ${GROUP_ID} developer && \
    useradd -u ${USER_ID} -g developer -s /bin/bash --home /home/developer developer && \
    mkdir -p /home/developer/.config/psysh && chown developer:developer /home/developer/.config/psysh

USER developer
