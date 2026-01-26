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

export PATH="$TOOLCHAIN/bin:$PATH"

export CC="clang"
export CXX="clang++"
export AR="llvm-ar"
export LD="ld.lld"
export STRIP="llvm-strip"

export CFLAGS="--target=${ARCH}-linux-android${ANDROID_API} --sysroot=$SYSROOT -O2 -fPIC"
export CXXFLAGS="$CFLAGS"
export LDFLAGS="--target=${ARCH}-linux-android${ANDROID_API} --sysroot=$SYSROOT"

export PKG_CONFIG=pkg-config
export PKG_CONFIG_LIBDIR="$SYSROOT/usr/lib/pkgconfig"
export PKG_CONFIG_SYSROOT_DIR="$SYSROOT"

rm -rf "$BUILD_DIR" "$OUTPUT_DIR"
mkdir -p "$BUILD_DIR" "$OUTPUT_DIR"
cd "$BUILD_DIR"

echo "⬇️ Descargando PHP $PHP_VERSION"
curl -LO https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz
tar -xf php-${PHP_VERSION}.tar.gz
cd php-${PHP_VERSION}

echo "⚙️ Configurando PHP para Android ARM64"

./configure \
  --build=x86_64-linux-gnu \
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
  --without-pear \
  --with-sysroot="$SYSROOT"

echo "🔨 Compilando"
make -j$(nproc)

echo "📦 Instalando"
make install DESTDIR="$OUTPUT_DIR/rootfs"

cd "$OUTPUT_DIR/rootfs"
tar -czf "$OUTPUT_DIR/php-${PHP_VERSION}-android-arm64.tar.gz" php

echo "✅ BUILD COMPLETADO"
echo "📦 output/php-${PHP_VERSION}-android-arm64.tar.gz"
