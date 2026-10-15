.PHONY: build install test test-mysql test-pgsql test-all shell coverage

build:
	docker compose build app

install:
	docker compose run --rm app composer install

test:
	docker compose run --rm app vendor/bin/pest

test-mysql:
	docker compose --profile mysql run --rm app-mysql vendor/bin/pest

test-pgsql:
	docker compose --profile pgsql run --rm app-pgsql vendor/bin/pest

test-all: test test-mysql test-pgsql

coverage:
	docker compose run --rm app vendor/bin/pest --coverage

shell:
	docker compose run --rm app sh
