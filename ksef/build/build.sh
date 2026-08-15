#!/bin/bash

set -e

# Check directory
if [ ! -f "core/modules/modKSEF.class.php" ]; then
    echo "Error: Must run from module root directory"
    exit 1
fi

echo "Starting KSeF module build..."

if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
else
  # Download composer.phar if not present
    if [ ! -f "composer.phar" ]; then
        echo "-> Downloading composer.phar..."
        curl -L -o composer.phar https://getcomposer.org/composer.phar
        chmod +x composer.phar
    fi
    COMPOSER="php composer.phar"
fi

# Install dependencies
echo "-> Installing production dependencies only..."
$COMPOSER install --no-dev --optimize-autoloader

# Scope
echo "-> Scoping dependencies..."
php build/scope-dependencies.php

# Build zip
echo "-> Creating release archive..."
php build/buildzip.php "$@"

echo "Build complete."
