#!/bin/sh
set -eu

cd /var/www/html

if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

php artisan migrate --force

if ! php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$ready = Illuminate\Support\Facades\Schema::hasTable("users")
    && Illuminate\Support\Facades\DB::table("users")->count() > 0;
exit($ready ? 0 : 1);
'; then
    php artisan db:seed --force
fi

if [ ! -f storage/oauth-private.key ]; then
    php artisan passport:keys --force
fi

php artisan storage:link || true

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
