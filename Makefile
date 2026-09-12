.PHONY: build install test analyse format format-check experiment benchmark shell

build:
	docker compose build

install: build
	docker compose run --rm php composer install

test:
	docker compose run --rm php composer test

analyse:
	docker compose run --rm php composer analyse

format:
	docker compose run --rm php composer format

format-check:
	docker compose run --rm php composer format:check

experiment:
	docker compose run --rm php php bin/experiment $(ARGS)

benchmark:
	docker compose run --rm php php bin/benchmark $(ARGS)

shell:
	docker compose run --rm php bash
