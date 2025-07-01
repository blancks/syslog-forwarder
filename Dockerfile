FROM php:8.2-alpine3.21

COPY syslog-forwarder.php /opt

WORKDIR /opt

CMD [ "php", "/opt/syslog-forwarder.php" ]
