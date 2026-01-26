#!/usr/bin/env bash
set -e

PHP_VERSION=8.0.28
ANDROID_API=21
ARCH=aarch64

ROOT_DIR="$(pwd)"
BUILD_DIR="$ROOT_DIR/build"
OUTPUT_DIR="$ROOT_DIR/output"

NDK="$ANDROID_NDK_HOME"
TOOLCHAIN="$NDK/toolchains/llvm/prebuilt/linux-x86_64"
SYSROOT="$TOOLCHAIN/sysroot"

export CC="$TOOLCHAIN/bin/${ARCH}-linux-android${ANDROID_API}-clang"
export CXX="$TOOLCHAIN/bin/${ARCH}-linux-android${ANDROID_API}-clang++"
export AR="$TOOLCHAIN/bin/llvm-ar"
export LD="$TOOLCHAIN/bin/ld"
export STRIP="$TOOLCHAIN/bin/llvm-strip"

export CFLAGS="--sysroot=$SYSROOT -O2 -fPIC"
export LDFLAGS="--sysroot=$SYSROOT"

rm -rf "$BUILD_DIR" "$OUTPUT_DIR"
mkdir -p "$BUILD_DIR" "$OUTPUT_DIR"
cd "$BUILD_DIR"

echo "⬇️ Descargando PHP $PHP_VERSION"
curl -LO https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz
tar -xf php-${PHP_VERSION}.tar.gz
cd php-${PHP_VERSION}

echo "⚙️ Configurando PHP para Android ARM64"

./configure \
  --host=${ARCH}-linux-android \
  --prefix=/php \
  --enable-cli \
  --disable-cgi \
  --disable-phpdbg \
  --disable-debug \
  --disable-rpath \
  --enable-phar \
  --enable-mbstring \
  --enable-bcmath \
  --enable-sockets \
  --enable-ctype \
  --with-openssl \
  --with-zlib \
  --with-curl \
  --with-iconv \
  --with-pdo-sqlite \
  --with-sqlite3 \
  --enable-opcache \
  --disable-opcache-jit \
  --without-pear

echo "🔨 Compilando"
make -j$(nproc)

echo "📦 Instalando"
make install DESTDIR="$OUTPUT_DIR/rootfs"

cd "$OUTPUT_DIR/rootfs"
tar -czf "$OUTPUT_DIR/php-${PHP_VERSION}-android-arm64.tar.gz" php

echo "✅ Listo"
echo "📦 output/php-${PHP_VERSION}-android-arm64.tar.gz"
