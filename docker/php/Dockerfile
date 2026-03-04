FROM php:8.3-fpm

ARG NODE_VERSION=20

# Set working directory
WORKDIR /var/www

ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

## Install dependencies
RUN apt-get update -y && \
    apt-get upgrade -y && \
    apt-get install -y apt-utils

RUN apt-get update --allow-releaseinfo-change && apt-get install -y \
        build-essential \
        locales \
        zip \
        nano \
        unzip \
        git \
        curl \
        gnupg \
        libzip-dev \
        htop \
        net-tools \
        supervisor \
        wget \
        cron \
        sudo \
        libwebp-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        tesseract-ocr \
        tesseract-ocr-all \
        inotify-tools \
    && apt-get update --allow-releaseinfo-change
RUN php -r "readfile('https://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer
RUN curl -sLS https://deb.nodesource.com/setup_$NODE_VERSION.x | bash -
RUN apt-get -y install nodejs
RUN docker-php-ext-install mysqli
RUN docker-php-ext-install pdo
RUN docker-php-ext-install pdo_mysql
RUN docker-php-ext-enable pdo_mysql
RUN docker-php-ext-install zip
RUN docker-php-ext-install exif
RUN docker-php-ext-install pcntl
RUN docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg --with-webp
RUN docker-php-ext-install gd
RUN docker-php-ext-install bcmath
RUN apt-get -y update --allow-releaseinfo-change
RUN apt-get install -y libicu-dev
RUN docker-php-ext-configure intl
RUN docker-php-ext-install intl
RUN docker-php-ext-install opcache
RUN docker-php-ext-install sockets

RUN apt-get install -y libmagickwand-dev

RUN apt-get install -y iputils-ping

RUN if ! pecl list | grep -q xdebug; then \
    pecl install xdebug && \
    docker-php-ext-enable xdebug; \
fi

RUN if ! pecl list | grep -q imagick; then \
    pecl install imagick && \
    docker-php-ext-enable imagick; \
fi

# Check if Redis extension is installed
RUN if ! pecl list | grep -q redis; then \
    pecl install redis && \
    docker-php-ext-enable redis; \
fi

RUN chown -R www-data:www-data /var/www

# create upload directories
RUN crontab -l | { echo "* * * * * cd /var/www && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1"; } | crontab -

RUN  mkdir -p /usr/local/bin

COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

RUN git config --system --add safe.directory /var/www

ENTRYPOINT ["start-container"]
