.PHONY: install lint lint-fix lint-php lint-php-fix lint-js lint-js-fix format format-check

install:
	composer install
	npm install

lint: lint-php lint-js

lint-fix: lint-php-fix lint-js-fix format

lint-php:
	composer lint

lint-php-fix:
	composer lint:fix

lint-js:
	npm run lint

lint-js-fix:
	npm run lint:fix

format:
	npm run format

format-check:
	npm run format:check
