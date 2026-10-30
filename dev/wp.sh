#!/usr/bin/env bash
# WP-CLI inside the wptest stack: dev/wp.sh plugin list
exec docker compose -p wptest -f "$(dirname "$0")/docker-compose.yml" run --rm -T cli wp --path=/var/www/html "$@" 2> >(grep -v -E "^ *Container |^$" >&2)
