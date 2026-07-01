# Use the official PHP Apache image
FROM php:8.2-apache

# Enable Apache rewrite module if needed (good practice)
RUN a2enmod rewrite

# Copy all project files to the Apache document root
COPY . /var/www/html/

# Create the SQLite database directory inside the container and grant permissions
# Apache runs as www-data, so it needs ownership of /var/www/html and the db subfolder
RUN mkdir -p /var/www/html/db \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/db

# Set the working directory
WORKDIR /var/www/html

# Expose HTTP port
EXPOSE 80
