.PHONY: install lint lint-php lint-js format-check

install:
	composer install
	npm install

lint: lint-php lint-js

lint-php:
	composer lint

lint-js:
	npm run lint

format-check:
	npm run format:check
