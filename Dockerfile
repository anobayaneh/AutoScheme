FROM php:8.2-cli

WORKDIR /app
COPY . /app

# Render sets $PORT; PHP's built-in server serves index.php as the router.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} index.php"]
