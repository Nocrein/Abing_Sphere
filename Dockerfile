FROM php:8.2-apache

# Disable ALL MPMs first, then enable only prefork
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy project files
COPY . /var/www/html/

# Fix Railway dynamic port
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80