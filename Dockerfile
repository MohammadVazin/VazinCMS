FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite-dev postgresql-dev curl-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql pdo_mysql mbstring curl

WORKDIR /app
COPY . /app

RUN mkdir -p /app/storage /app/public/uploads \
    && chmod -R 0750 /app/storage /app/public/uploads \
    && chmod +x /app/bin/vazin

ENV APP_ENV=production \
    DB_CONNECTION=sqlite \
    SESSION_SECURE=false \
    PAGE_CACHE_ENABLED=true

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
