FROM php:8.2-cli

# Устанавливаем curl-расширение, которое нужно для запросов к Монобанку
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Копируем все файлы твоего прокси в контейнер
WORKDIR /app
COPY . .

# Render автоматически передает номер порта в переменную $PORT.
# Запускаем встроенный сервер PHP на этом порту.
CMD sh -c "php -S 0.0.0.0:$PORT"
