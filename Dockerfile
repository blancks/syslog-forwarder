FROM php:8.2-alpine3.21

ENV TZ=Europe/Berlin

COPY php.ini /usr/local/etc/php

COPY syslog-forwarder.php /opt

WORKDIR /opt

ENV FRITZBOX_ENDPOINT=http://192.168.1.1 \
    SYSLOG_MESSAGE_IDENTIFIER=FRITZ!Box \
    REFRESH_INTERVAL_SECONDS=5 \
    MAX_RETRIES_ALLOWED=3

CMD [ "php", "/opt/syslog-forwarder.php" ]
