#!/bin/sh
set -eu

port="${PORT:-80}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT must be an integer between 1 and 65535." >&2
        exit 1
        ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "PORT must be an integer between 1 and 65535." >&2
    exit 1
fi

link_persistent_dir() {
    source_dir="$1"
    persistent_dir="$2"
    mkdir -p "$persistent_dir"

    if [ -L "$source_dir" ]; then
        if [ "$(readlink -f "$source_dir")" != "$(readlink -f "$persistent_dir")" ]; then
            echo "Persistent link target mismatch: $source_dir" >&2
            exit 1
        fi
        return
    fi

    mkdir -p "$source_dir"
    cp -an "$source_dir/." "$persistent_dir/"
    rm -rf "$source_dir"
    ln -s "$persistent_dir" "$source_dir"
}

mkdir -p /data/sessions /data/topup_proofs
link_persistent_dir /var/www/html/images/user_uploads /data/user_uploads
link_persistent_dir /var/www/html/images/admin_uploads /data/admin_uploads
link_persistent_dir /var/www/html/uploads /data/verification_uploads
chown -R www-data:www-data /data
chmod 700 /data/sessions /data/topup_proofs /data/verification_uploads

if [ "${APP_ENV:-development}" = "production" ]; then
    cookie_secure=1
else
    cookie_secure=0
fi
printf '%s\n' \
    'session.save_path = "/data/sessions"' \
    'session.use_strict_mode = 1' \
    'session.cookie_httponly = 1' \
    'session.cookie_samesite = Lax' \
    "session.cookie_secure = ${cookie_secure}" \
    > /usr/local/etc/php/conf.d/zz-six-origins-session.ini

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

if ! apache2ctl -t; then
    echo "Apache configuration is invalid. Enabled MPM directives:" >&2
    grep -RniE '^[[:space:]]*LoadModule[[:space:]]+mpm_' \
        /etc/apache2/apache2.conf \
        /etc/apache2/mods-enabled \
        /etc/apache2/conf-enabled \
        /etc/apache2/sites-enabled 2>/dev/null || true
    exit 1
fi

mpm_modules="$(apache2ctl -M | awk '$1 ~ /^mpm_/ && $2 == "(shared)" { print $1 }')"
if [ "$mpm_modules" != "mpm_prefork_module" ]; then
    echo "Expected only mpm_prefork_module to be loaded; found: ${mpm_modules:-none}" >&2
    exit 1
fi

exec apache2-foreground
