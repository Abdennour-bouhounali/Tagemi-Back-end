# Server load: production steps

The code changes work with the current settings. These steps happen on the
server, outside visit hours. Deploy the backend first (it installs `predis`).

## 1. Redis cache (do this first)

    apt install redis-server
    systemctl enable --now redis-server
    redis-cli ping                        # PONG

In `/var/www/Tagemi-Back-end/.env`:

    CACHE_STORE=redis
    REDIS_CLIENT=predis
    LOG_LEVEL=warning

Then, as `deploy`: `php artisan config:cache`, and `systemctl restart php8.5-fpm`.
Rate-limit counters restart from zero. Rollback: `CACHE_STORE=database`, same two commands.

## 2. PHP-FPM workers (after load has dropped)

In `/etc/php/8.5/fpm/pool.d/www.conf`, about 55 MB per worker with 1.3 GB free:

    pm.max_children = 10
    pm.start_servers = 3
    pm.min_spare_servers = 2
    pm.max_spare_servers = 5

Raising this while the CPU is still at 100 % adds no throughput.

## 3. CPU

Check `nproc`. At 1-2 cores, adding vCPUs is the quickest relief.

## 4. Optional: nginx cache for the public hall endpoints

Not tested: there is no nginx on the development machine. The application
already shares these answers for 3 s; this also skips PHP. In `http {}`:

    fastcgi_cache_path /var/cache/nginx/tagemi keys_zone=tagemi_public:10m max_size=50m inactive=1m;

In the API server block, a location for `/api/public/waitinglist` and
`/api/public/events/` copying the existing PHP location, with `SCRIPT_FILENAME`
set to `public/index.php`, plus:

    fastcgi_cache tagemi_public;
    fastcgi_cache_key $request_method$request_uri;
    fastcgi_cache_methods GET HEAD;
    fastcgi_cache_valid 200 5s;
    fastcgi_cache_lock on;
    fastcgi_ignore_headers Cache-Control Expires Set-Cookie;

Run `nginx -t` before reloading.
