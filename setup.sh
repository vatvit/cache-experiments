#!/bin/bash

# Setup script for PHP project with Docker and Composer
set -e

echo "🐳 Starting Docker setup and Composer dependency installation..."

# Check if Docker is running
if ! docker info >/dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker first."
    exit 1
fi

echo "✅ Docker is running"

# Create a PHP container to run Composer
echo "🔧 Setting up PHP environment with Docker..."

# Install Composer dependencies using PHP CLI Docker container
echo "📦 Installing Composer dependencies..."
docker run --rm \
    -v "$(pwd)":/app \
    -w /app \
    php:8.4-cli \
    sh -c "apt-get update && apt-get install -y git unzip && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && composer install"

echo "✅ Setup completed successfully!"
echo "🚀 Your PHP project is ready to use."
