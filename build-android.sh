#!/usr/bin/env bash
set -e

PHP_VERSION=8.0.28
ANDROID_API=21
ARCH=aarch64
NDK=$ANDROID_NDK_HOME

TOOLCHAIN=$NDK/toolchains/llvm/prebuilt/linux-x86_64
SYSROOT=$TOOLCHAIN/sysroot

export CC=$TOOLCHAIN/bin/aarch64-linux-android${ANDROID_API}-clang
export CXX=$TOOLCHAIN/bin/aarch64-linux-android${ANDROID_API}-clang++
export AR=$TOOLCHAIN/bin/llvm-ar
export LD=$TOOLCHAIN/bin/ld
export STRIP=$TOOLCHAIN/bin/llvm-strip

export CFLAGS="--sysroot=$SYSROOT -O2 -fPIC"
export LDFLAGS="--sysroot=$SYSROOT"

curl -LO https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz
tar -xf php-${PHP_VERSION}.tar.gz
cd php-${PHP_VERSION}

./configure \
  --host=aarch64-linux-android \
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

make -j$(nproc)
make install DESTDIR=$PWD/output

cd output
tar -czf php-8.0.28-android-arm64.tar.gz php
