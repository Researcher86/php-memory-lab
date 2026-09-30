.PHONY: up down build shell htop install test analyse format format-check \
        experiment experiment-debug benchmark benchmark-debug

# CI has no terminal to attach to (GitHub Actions sets CI=true), so exec runs
# without one there; locally it keeps the TTY for colours and interactive use.
TTY := $(if $(CI),-T,)

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec $(TTY) php bash

htop: up
	docker compose exec $(TTY) php htop

install: up
	docker compose exec $(TTY) php composer install

test: up
	docker compose exec $(TTY) php composer test

analyse: up
	docker compose exec $(TTY) php composer analyse

format: up
	docker compose exec $(TTY) php composer format

format-check: up
	docker compose exec $(TTY) php composer format:check

experiment: up
	docker compose exec $(TTY) php php bin/experiment $(ARGS)

benchmark: up
	docker compose exec $(TTY) php php bin/benchmark $(ARGS)

# The image ships xdebug with start_with_request=trigger, so nothing reaches
# for a debugger unless asked - which matters here, where one experiment is a
# parent plus N forked children. These targets are the ask; point your IDE at
# port 9003 first, or the connection attempt just times out and the run
# continues.

experiment-debug: up
	docker compose exec $(TTY) php bash -c "XDEBUG_TRIGGER=1 php bin/experiment $(ARGS)"

benchmark-debug: up
	docker compose exec $(TTY) php bash -c "XDEBUG_TRIGGER=1 php bin/benchmark $(ARGS)"