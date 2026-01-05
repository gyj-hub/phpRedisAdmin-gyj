# phpRedisAdmin Docker Image
# A simple web interface to manage Redis databases with cluster support
# Based on composer:2.2 image which includes PHP and Composer

FROM composer:2.2

# Metadata labels
LABEL version="1.28.0"
LABEL maintainer="phpRedisAdmin-gyj"
LABEL description="phpRedisAdmin with type filtering and Redis Cluster support"
LABEL org.opencontainers.image.title="phpRedisAdmin"
LABEL org.opencontainers.image.version="1.28.0"
LABEL org.opencontainers.image.description="Simple web interface to manage Redis databases with cluster support"

# Install necessary utilities
# tini: lightweight init system for proper signal handling
# tzdata: timezone data for correct time display
RUN apk add --no-cache tini tzdata

# Set working directory
WORKDIR /src/app

# Copy application files
COPY . .

# Install PHP dependencies via Composer
# Use config.environment.inc.php for environment variable support
RUN set -xe; \
    composer install; \
    cp includes/config.environment.inc.php includes/config.inc.php

# Default port (can be overridden via environment variable)
ENV PORT=80
EXPOSE 80

# Start PHP built-in web server
# tini ensures proper signal handling and zombie process reaping
ENTRYPOINT [ "sh", "-c", "tini -- php -S 0.0.0.0:$PORT" ]
