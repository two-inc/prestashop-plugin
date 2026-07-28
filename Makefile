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
PHPSTAN_VERSION      := 2.2.5
# Re-derive this hash from the official GitHub release page/checksums when
# bumping PHPSTAN_VERSION — never copy it from elsewhere.
PHPSTAN_SHA256       := 1b2f03384ebcfd67053b06b69cbc0b9f62bf239349f69eaf723649409789e2e6
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

.PHONY: help install configure run debug stop clean flush logs proxy archive test test-integration carrierless-shop carrierless-off bump patch minor major format phpstan bumpver-patch bumpver-minor bumpver-major

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-18s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

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

# Carrier-less shipping (TWO-25200 / TWO-25217). A shop where shipping is
# priced but no carrier declares a tax rules group for it — the shape the
# optional "Default shipping tax code" setting exists for. Not part of
# `install`: it is a deliberately unusual shop, not a default dev shop.
#
# What it does, all of it reversible with `make carrierless-off`:
#   - installs tests/integration/fixtures/twocarrierlesstest, which injects a
#     priced delivery option belonging to no carrier
#   - creates a customer, a company address, a 25% tax rules group and a cart
#     carrying that delivery selection (id_carrier = 0)
#   - adds define('_TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_', true) to
#     config/defines_custom.inc.php, which is what reveals the "Default
#     shipping tax code" dropdown in the module's Advanced settings
## Set up the local shop for carrier-less shipping + reveal the hidden "Default shipping tax code" field
carrierless-shop:
	PS_CONTAINER=$(CONTAINER) dev/ci/seed-carrierless-cart.sh
	docker exec $(CONTAINER) bash /var/www/html/modules/$(MODULE_NAME)/dev/enable-default-shipping-tax-code
	@echo ""
	@echo "========================================="
	@echo " Carrier-less shipping is set up."
	@echo " Admin field:  $(URL)admin-dev -> Modules -> Two -> Configure -> Advanced settings"
	@echo "               -> 'Default shipping tax code'"
	@echo " Probe it:     make test-integration"
	@echo " Undo:         make carrierless-off"
	@echo "========================================="

## Undo make carrierless-shop: hide the "Default shipping tax code" field again
carrierless-off:
	docker exec $(CONTAINER) bash /var/www/html/modules/$(MODULE_NAME)/dev/enable-default-shipping-tax-code --reset

## Run the tests/integration probes against the running local shop (run make carrierless-shop first)
test-integration:
	PS_CONTAINER=$(CONTAINER) dev/ci/run-integration-probes.sh

## Format PHP module source with php-cs-fixer (PSR-12)
format:
	docker run --rm -u "$$(id -u):$$(id -g)" -v "$(CURDIR)":/app -w /app php:8.2-cli bash -c "\
		php -r \"copy('https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v$(PHP_CS_FIXER_VERSION)/php-cs-fixer.phar', '/tmp/php-cs-fixer.phar');\" \
		&& echo '$(PHP_CS_FIXER_SHA256)  /tmp/php-cs-fixer.phar' | sha256sum -c - \
		&& php /tmp/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php"

## Run phpstan static analysis (same gate CI runs)
phpstan:
	docker run --rm -v "$(CURDIR)":/app -w /app php:8.2-cli bash -c "\
		php -r \"copy('https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar', '/tmp/phpstan.phar');\" \
		&& echo '$(PHPSTAN_SHA256)  /tmp/phpstan.phar' | sha256sum -c - \
		&& php /tmp/phpstan.phar analyse --configuration=phpstan.neon --no-progress --memory-limit=1G"

# Requires bumpver on PATH (pip install bumpver / pipx install bumpver),
# same implicit prerequisite as the sibling plugin repos.
# tag/push are off in bumpver.toml — this only commits the bump locally.
#
# Version-bump convention (TWO-25230): patch on staging, minor on main, major
# via the escape hatch. The staging half is now automated in
# .github/workflows/version-bump.yml, so DO NOT hand-run a bump for a PR into
# staging any more — the workflow fires on the merge and you would double-bump.
#
# Prefer `make bump`: it asks .github/scripts/decide-bump-level.sh for the
# level rather than leaving it to whoever is at the keyboard. The explicit
# patch/minor/major targets remain for a deliberate override.
bumpver-%:
	SKIP=commit-msg bumpver update --$*

## Bump the version at the level the convention says
bump:
	@branch="$$(git rev-parse --abbrev-ref HEAD)"; \
	out="$$(.github/scripts/decide-bump-level.sh "$$branch")"; \
	level="$$(printf '%s\n' "$$out" | sed -n 's/^level=//p')"; \
	set_version="$$(printf '%s\n' "$$out" | sed -n 's/^set_version=//p')"; \
	reason="$$(printf '%s\n' "$$out" | sed -n 's/^reason=//p')"; \
	if [ -n "$$set_version" ]; then \
		echo "Convention says major -> $$set_version ($$reason)"; \
		SKIP=commit-msg bumpver update --set-version "$$set_version"; \
	else \
		echo "Convention says $$level ($$reason)"; \
		SKIP=commit-msg bumpver update --$$level; \
	fi

## Bump patch version (prefer `make bump`)
patch: bumpver-patch

## Bump minor version (prefer `make bump`)
minor: bumpver-minor

## Bump major version (prefer `make bump`)
major: bumpver-major

## Create a versioned zip archive
archive:
	git archive --format zip --prefix=$(MODULE_NAME)/ HEAD > $(MODULE_NAME).zip
