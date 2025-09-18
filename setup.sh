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

# Run Composer install using official Composer Docker image
echo "📦 Installing Composer dependencies..."
docker run --rm -v "$(pwd)":/app -w /app composer:latest install --no-dev --optimize-autoloader

# Optional: Run Composer install with dev dependencies for development
echo "🛠️  Installing development dependencies..."
docker run --rm -v "$(pwd)":/app -w /app composer:latest install --optimize-autoloader

echo "✅ Setup completed successfully!"
echo "🚀 Your PHP project is ready to use."

# Display useful information
echo ""
echo "📋 Next steps:"
echo "   - Run tests: docker run --rm -v \"\$(pwd)\":/app -w /app php:8.0-cli vendor/bin/phpunit"
echo "   - Or use: docker run --rm -v \"\$(pwd)\":/app -w /app composer:latest run-script test"
