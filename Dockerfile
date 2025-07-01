FROM php:8.2-alpine3.21

ENV TZ=Europe/Berlin

COPY php.ini /usr/local/etc/php
COPY syslog-forwarder.php /opt

WORKDIR /opt

CMD [ "php", "/opt/syslog-forwarder.php" ]
