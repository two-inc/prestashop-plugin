# ==============================================================================
# Development environment
# ==============================================================================

-include .env.local

CONTAINER  := prestashop
DB_CONTAINER := prestashop-db
COMPOSE    := docker compose
PORT       := 1237
URL        := http://localhost:$(PORT)/

MODULE_NAME := twopayment
PHP_CS_FIXER_VERSION := 3.92.0
# Re-derive this hash from the official GitHub release page/checksums when
# bumping PHP_CS_FIXER_VERSION — never copy it from elsewhere.
PHP_CS_FIXER_SHA256  := 7ae9440e7ac8dca47d632cf07719d43bc65c0deef460d95c3cfea81979895f99
ADMIN_MAIL  := exampleuser@two.inc
ADMIN_PASSWD := examplepassword123
export PORT

# Internal Two devs (@two.inc gcloud account) point at staging; everyone else
# at sandbox. Mirrors the Magento plugin convention. Override either via
# .env.local or `make ... TWO_ENV=...`.
TWO_ENV              := $(shell gcloud config get-value account 2>/dev/null | grep -q '@two\.inc$$' && echo staging || echo sandbox)
TWO_API_BASE_URL     ?= https://api.$(TWO_ENV).two.inc
TWO_PORTAL_BASE_URL  ?= https://portal.$(TWO_ENV).two.inc
export TWO_API_BASE_URL TWO_PORTAL_BASE_URL
# Plugin admin config only exposes 'sandbox' vs 'production' â€” TWO_ENV
# selects the API URL the dev loop hits, TWO_ENVIRONMENT selects the
# plugin's admin-side mode.
TWO_ENVIRONMENT      ?= sandbox
TWO_STORE_COUNTRY    ?= NO
export TWO_STORE_COUNTRY

.PHONY: help install configure run debug stop clean flush logs proxy archive test patch minor major format bumpver-patch bumpver-minor bumpver-major

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-16s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

## Create PrestaShop + MariaDB containers, install the Two module
install: clean
	$(COMPOSE) up -d
	@echo "Waiting for PrestaShop install to complete (auto-install can take 60-120s)..."
	@until docker exec $(CONTAINER) bash -c '[ -f /var/www/html/config/settings.inc.php ] || [ -f /var/www/html/app/config/parameters.php ]' 2>/dev/null; do sleep 3; done
	@until ! docker exec $(CONTAINER) bash -c '[ -d /var/www/html/install ]' 2>/dev/null; do sleep 3; done
	@echo "Fixing var/ permissions (admin 500 fix)..."
	docker exec $(CONTAINER) bash -c "chown -R www-data:www-data /var/www/html/var && chmod -R 775 /var/www/html/var"
	@echo "Installing module $(MODULE_NAME)..."
	docker exec -u www-data $(CONTAINER) bash -c "cd /var/www/html && php -d memory_limit=512M bin/console prestashop:module install $(MODULE_NAME)"
	@echo "Enabling Two-supported countries and extending carrier coverage..."
	docker exec $(DB_CONTAINER) mysql -uroot -padmin prestashop -e "\
		UPDATE ps_country SET active=1 WHERE iso_code IN ('NO','GB','SE','DK','FI','NL','DE'); \
		INSERT IGNORE INTO ps_module_country (id_module, id_shop, id_country) \
		  SELECT m.id_module, 1, co.id_country FROM ps_module m \
		  CROSS JOIN ps_country co \
		  WHERE m.name='$(MODULE_NAME)' \
		    AND co.iso_code IN ('NO','GB','SE','DK','FI','NL','DE'); \
		INSERT IGNORE INTO ps_carrier_zone (id_carrier, id_zone) \
		  SELECT c.id_carrier, co.id_zone FROM ps_carrier c \
		  CROSS JOIN ps_country co \
		  WHERE c.active=1 AND c.deleted=0 \
		    AND co.iso_code IN ('NO','GB','SE','DK','FI','NL','DE');"
	$(MAKE) configure TWO_API_KEY=$(or $(TWO_API_KEY),dummy-dev-key) TWO_ENVIRONMENT=$(TWO_ENVIRONMENT)
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash /var/www/html/modules/$(MODULE_NAME)/dev/patch-proxy "$$PROXY_URL"; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " PrestaShop store: $(URL)"; \
	echo " Admin panel:      $(URL)admin-dev"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:     $$PROXY_URL/"; \
		echo " Proxy admin:     $$PROXY_URL/admin-dev"; \
	fi; \
	echo " Credentials:      $(ADMIN_MAIL) / $(ADMIN_PASSWD)"; \
	echo "========================================="

## Update Two payment config (writes key + calls verify_api_key so the
## plugin actually appears at checkout): make configure TWO_API_KEY=xxx
configure:
	docker exec \
		-u www-data \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		-e TWO_ENVIRONMENT=$(TWO_ENVIRONMENT) \
		-e TWO_API_BASE_URL=$(TWO_API_BASE_URL) \
		$(CONTAINER) php /var/www/html/modules/$(MODULE_NAME)/dev/configure.php
	docker exec $(CONTAINER) bash -c "rm -rf /var/www/html/var/cache/*"

## Start PrestaShop containers and FRP proxy
run:
	$(COMPOSE) start
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash /var/www/html/modules/$(MODULE_NAME)/dev/patch-proxy "$$PROXY_URL"; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " PrestaShop store: $(URL)"; \
	echo " Admin panel:      $(URL)admin-dev"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:     $$PROXY_URL/"; \
		echo " Proxy admin:     $$PROXY_URL/admin-dev"; \
	fi; \
	echo " Credentials:      $(ADMIN_MAIL) / $(ADMIN_PASSWD)"; \
	echo "========================================="

## Start with PS_DEV_MODE on and caches cleared (hot reload)
debug: run
	docker exec $(CONTAINER) bash -c "rm -rf /var/www/html/var/cache/*"
	@echo "PS_DEV_MODE active (verbose errors, cache cleared)"

## Stop PrestaShop containers and FRP proxy
stop:
	-./start-proxy.sh stop 2>/dev/null
	-docker exec $(CONTAINER) bash /var/www/html/modules/$(MODULE_NAME)/dev/patch-proxy --reset 2>/dev/null
	$(COMPOSE) stop

## Clear PrestaShop cache
flush:
	docker exec $(CONTAINER) bash -c "rm -rf /var/www/html/var/cache/*"

## Remove the PrestaShop containers + volumes and stop proxy
clean:
	-./start-proxy.sh stop 2>/dev/null
	-$(COMPOSE) down -v 2>/dev/null

## Run FRP proxy in foreground (Ctrl-C to stop)
proxy:
	./start-proxy.sh

## Tail PrestaShop + module logs
logs:
	docker exec $(CONTAINER) bash -c "tail -f /var/www/html/var/logs/*.log /var/log/apache2/error.log 2>/dev/null"

## Run the unit test harness (same suite CI runs)
test:
	docker run --rm -v "$(CURDIR)":/app -w /app php:8.2-cli php tests/run.php

## Format PHP module source with php-cs-fixer (PSR-12)
format:
	docker run --rm -u "$$(id -u):$$(id -g)" -v "$(CURDIR)":/app -w /app php:8.2-cli bash -c "\
		php -r \"copy('https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v$(PHP_CS_FIXER_VERSION)/php-cs-fixer.phar', '/tmp/php-cs-fixer.phar');\" \
		&& echo '$(PHP_CS_FIXER_SHA256)  /tmp/php-cs-fixer.phar' | sha256sum -c - \
		&& php /tmp/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php"

# Requires bumpver on PATH (pip install bumpver / pipx install bumpver),
# same implicit prerequisite as magento-plugin / woocommerce-plugin.
# tag/push are off in bumpver.toml — this only commits the bump locally.
bumpver-%:
	SKIP=commit-msg bumpver update --$*

## Bump patch version
patch: bumpver-patch

## Bump minor version
minor: bumpver-minor

## Bump major version
major: bumpver-major

## Create a versioned zip archive
archive:
	git archive --format zip --prefix=$(MODULE_NAME)/ HEAD > $(MODULE_NAME).zip
