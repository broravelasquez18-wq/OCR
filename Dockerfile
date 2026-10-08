# =====================================================================
# Verificador OCR - imagen PHP 8.2 + Apache con Tesseract y OpenCV
# =====================================================================
FROM php:8.2-apache

# --- Dependencias de sistema, extensiones PHP y stack de OCR ----------
RUN apt-get update && apt-get install -y --no-install-recommends \
      tesseract-ocr tesseract-ocr-spa \
      python3 python3-pip libgl1 \
      libicu-dev libzip-dev \
      libglib2.0-0 \
      unzip \
 && docker-php-ext-install pdo_mysql zip intl \
 && pip3 install --break-system-packages --no-cache-dir \
      opencv-python-headless pytesseract numpy rapidocr-onnxruntime zxing-cpp \
 && rm -rf /var/lib/apt/lists/*

# --- Composer ---------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Apache: mod_rewrite + DocumentRoot a public/ ---------------------
RUN a2enmod rewrite
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# --- Dependencias PHP (cachea capa si no cambian composer.*) ----------
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader || true

# --- Codigo de la aplicacion ------------------------------------------
COPY . .

# Reintentar install ahora que esta todo el codigo (por si no habia lock).
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- Permisos de uploads ----------------------------------------------
RUN mkdir -p storage/uploads \
 && chown -R www-data:www-data storage \
 && chmod -R 775 storage

EXPOSE 80
