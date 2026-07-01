FROM ubuntu:20.04

# Prevent interactive prompts during installation
ENV DEBIAN_FRONTEND=noninteractive

# Install dependencies
RUN apt-get update && apt-get install -y \
    apache2 \
    php7.4-fpm \
    php7.4-mysql \
    php7.4-mbstring \
    php7.4-zip \
    php7.4-gd \
    php7.4-curl \
    php7.4-soap \
    php7.4-ssh2 \
    php-apcu \
    libssh2-1 \
    jq \
    curl \
    unzip \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod mpm_event proxy_fcgi setenvif rewrite deflate headers
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Enable PHP-FPM configuration in Apache
RUN a2enconf php7.4-fpm

# Create the application directory
RUN mkdir -p /var/www/html/ZZPanelBot

# Set working directory
WORKDIR /var/www/html/ZZPanelBot

# Copy application code (excluding files in .dockerignore)
COPY . /var/www/html/ZZPanelBot

# Copy config templates and custom settings
COPY docker/php/botpanel-perf.ini /etc/php/7.4/fpm/conf.d/99-botpanel-perf.ini
COPY docker/apache/botpanel.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/botpanel-perf.conf /etc/apache2/conf-available/botpanel-perf.conf

# Enable custom Apache performance config
RUN a2enconf botpanel-perf

# Copy entrypoint script
COPY docker-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose port 80
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
