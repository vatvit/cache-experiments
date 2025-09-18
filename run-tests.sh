#!/bin/bash

# Shell script to run tests in Docker container
set -e

echo "🧪 Running tests in Docker container..."

# Check if Docker is running
if ! docker info >/dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker first."
    exit 1
fi

echo "✅ Docker is running"

# Check if vendor directory exists (dependencies installed)
if [ ! -d "vendor" ]; then
    echo "❌ Vendor directory not found. Please run setup.sh first to install dependencies."
    exit 1
fi

echo "✅ Dependencies found"

# Run PHPUnit tests using PHP Docker container
echo "🚀 Executing PHPUnit tests..."
docker run --rm \
    -v "$(pwd)":/app \
    -w /app \
    php:8.4-cli \
    vendor/bin/phpunit

echo "✅ Tests completed!"
