DC = docker compose -f docker-compose.yml exec -T app

.PHONY: test phpstan cs fix

test:
	$(DC) vendor/bin/phpunit --configuration=phpunit.dist.xml

phpstan:
	$(DC) php -d memory_limit=512M vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --no-progress

cs:
	$(DC) vendor/bin/php-cs-fixer check --diff

fix:
	$(DC) vendor/bin/php-cs-fixer fix
