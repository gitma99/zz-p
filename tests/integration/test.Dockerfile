FROM php:7.4-cli
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
    gcc make autoconf pkg-config >/dev/null 2>&1 \
    && docker-php-ext-install mysqli >/dev/null 2>&1 \
    && apt-get purge -y --auto-remove gcc make autoconf pkg-config \
    && apt-get clean -qq && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
