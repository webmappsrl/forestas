FROM php:8.4.27-fpm

USER root

ADD .bashrc /var/www/.bashrc

ADD www.conf /usr/local/etc/php-fpm.d/www.conf
ADD zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
ADD php.ini /usr/local/etc/php/php.ini

ENV TZ 'Europe/Rome'

RUN chown -R www-data:www-data /var/www \
    && chown  www-data:www-data /usr/local/etc/php-fpm.d/www.conf \
    && chown -R www-data:www-data /usr/local/etc/php-fpm.d/zz-docker.conf \
    && apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmcrypt-dev \
    libpng-dev \
    zlib1g-dev \
    libicu-dev \
    g++ \
    imagemagick \
    libmagickwand-dev \
    libmagickcore-dev \
    git \
    unzip \
    vim \
    libzip-dev \
    libwebp-dev \
    libpq-dev \
    osmium-tool \
    osm2pgsql \
    build-essential \
    cmake \
    libboost-dev \
    libboost-program-options-dev \
    libboost-filesystem-dev \
    libboost-regex-dev \
    libexpat1-dev \
    zlib1g-dev \
    libbz2-dev \
    liblz4-dev \
    libprotobuf-dev \
    protobuf-compiler \
    libosmium2-dev \
    libprotozero-dev \
    supervisor \
    gdal-bin \
    zip \
    nodejs npm \
    postgresql-common

RUN /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -v 17 \
    && apt-get install -y postgresql-client-17 \
    && update-alternatives --install /usr/bin/pg_dump pg_dump /usr/lib/postgresql/17/bin/pg_dump 100

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# TeX Live per la compilazione dei PDF di stampa (oc:8982): lo script e VERSION vengono dal package
COPY --from=wmpackage install-deps.sh VERSION /tmp/wm-print/
RUN bash /tmp/wm-print/install-deps.sh && rm -rf /tmp/wm-print

# Work Sans (licenza OFL), font dei template di stampa. Servono i TTF statici: XeLaTeX non usa i
# font variabili. Pesi 300-700 e corsivo 400, dal repo upstream a un commit fissato, con sha256.
ARG WORKSANS_COMMIT=b35c81086186162164947bd39574683073d9b268
RUN set -eux; \
    base="https://raw.githubusercontent.com/weiweihuanghuang/Work-Sans/${WORKSANS_COMMIT}"; \
    dir=/usr/local/share/fonts/work-sans; \
    mkdir -p "$dir"; \
    curl -fsSL "$base/fonts/ttf/WorkSans-Light.ttf" -o "$dir/WorkSans-Light.ttf"; \
    echo "64e857d0a24ba3825c3f8b888ab5abade9c7b61166504b17317feab3cb74b64e  $dir/WorkSans-Light.ttf" | sha256sum -c -; \
    curl -fsSL "$base/fonts/ttf/WorkSans-Regular.ttf" -o "$dir/WorkSans-Regular.ttf"; \
    echo "c4df840f74d05fddda0e716c9991f6d176bb278e43c39c8817760bbcbfdad3c5  $dir/WorkSans-Regular.ttf" | sha256sum -c -; \
    curl -fsSL "$base/fonts/ttf/WorkSans-Medium.ttf" -o "$dir/WorkSans-Medium.ttf"; \
    echo "46bd48f4185ed341f24600527a7506aa84263cfe2f1a61fa9485076b5c95026f  $dir/WorkSans-Medium.ttf" | sha256sum -c -; \
    curl -fsSL "$base/fonts/ttf/WorkSans-SemiBold.ttf" -o "$dir/WorkSans-SemiBold.ttf"; \
    echo "083b456dcbd3f8b1227ee1db508c8a247a85a0f7d1ffe3396589b7ab4641359e  $dir/WorkSans-SemiBold.ttf" | sha256sum -c -; \
    curl -fsSL "$base/fonts/ttf/WorkSans-Bold.ttf" -o "$dir/WorkSans-Bold.ttf"; \
    echo "e32ca4477095e3b259915d3db0b2acd1091e9d142706a54ba3eb2dcc54184c41  $dir/WorkSans-Bold.ttf" | sha256sum -c -; \
    curl -fsSL "$base/fonts/ttf/WorkSans-Italic.ttf" -o "$dir/WorkSans-Italic.ttf"; \
    echo "24894df6fcdeb4f1fc993bb8d7f7c6477a64cbb99d7ba5676f972cd9a8ba2143  $dir/WorkSans-Italic.ttf" | sha256sum -c -; \
    curl -fsSL "$base/OFL.txt" -o "$dir/OFL.txt"; \
    echo "749aca05078664ce682dce1b1b10096ac397cb088c1a6df4e1bb56f0092a9272  $dir/OFL.txt" | sha256sum -c -; \
    fc-cache -f

RUN docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
    gd \
    intl \
    zip \
    pdo \
    pdo_mysql \
    opcache \
    pgsql \
    pdo_pgsql \
    exif \
    pcntl 

RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && ln -s /usr/local/bin/composer /usr/bin/composer

ENV HOME /var/www
WORKDIR /var/www

USER www-data
