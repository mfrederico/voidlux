# Stage 1: Composer binary
FROM composer:2 AS composer

# Stage 2: Runtime
FROM php:8.2-cli

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    tmux \
    git \
    bash \
    procps \
    iproute2 \
    libsqlite3-dev \
    nodejs \
    npm \
    curl \
    wget \
    # X11 and Desktop Environment
    xvfb \
    xdotool \
    imagemagick \
    x11-utils \
    x11vnc \
    # Lightweight desktop environment
    xfce4 \
    xfce4-terminal \
    # Window manager alternative (lighter than full XFCE)
    fluxbox \
    # GUI Applications
    firefox-esr \
    gedit \
    thunar \
    feh \
    evince \
    scrot \
    # Development tools
    vim-gtk3 \
    # Utilities
    net-tools \
    xclip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install pdo_sqlite sockets

# OpenSwoole via PECL
RUN pecl install openswoole && docker-php-ext-enable openswoole

# Composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (layer caching)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application
COPY . .

# Re-run autoload dump after full copy
RUN composer dump-autoload --no-dev --optimize

# Persistent data and agent workspace
RUN mkdir -p /app/data /app/workbench
VOLUME ["/app/data", "/app/workbench"]

# Install noVNC client (JS only — WebSocket relay is handled by OpenSwoole)
RUN git clone https://github.com/novnc/noVNC.git /opt/noVNC \
    && ln -s /opt/noVNC/vnc.html /opt/noVNC/index.html

# Install Claude Code CLI globally via npm
RUN npm install -g @anthropic-ai/claude-code

# Seneschal HTTP, Emperor HTTP, P2P ports
EXPOSE 9090 9091 7100 7101

RUN chmod +x /app/scripts/docker-entrypoint.sh

ENTRYPOINT ["/app/scripts/docker-entrypoint.sh"]
