# Test runner for the integration tests: PHP 8.4 with the MariaDB and
# PostgreSQL command line clients. See tests/Integration/README.md.
FROM alpine:3.22

RUN apk add --no-cache \
        mariadb-client postgresql17-client \
        php84 php84-mbstring php84-tokenizer php84-dom php84-xml php84-xmlwriter \
        php84-ctype php84-fileinfo php84-pcntl php84-posix php84-iconv php84-session \
        php84-phar php84-openssl php84-simplexml php84-pdo \
    && ln -sf /usr/bin/php84 /usr/bin/php

WORKDIR /app
