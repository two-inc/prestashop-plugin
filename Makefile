# ==============================================================================
# Development environment
# ==============================================================================

-include .env.local

CONTAINER  := prestashop
DB_CONTAINER := prestashop-db
COMPOSE    := docker compose
PORT       := 1235
URL        := http://localhost:$(PORT)/

MODULE_NAME := twopayment
ADMIN_MAIL  := admin@two.inc
ADMIN_PASSWD := examplepassword123
export PORT

TWO_ENVIRONMENT      ?= sandbox
TWO_STORE_COUNTRY    ?= NO
export TWO_STORE_COUNTRY

.PHONY: help install configure run debug stop clean flush logs proxy archive

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
	docker exec $(CONTAINER) bash -c "cd /var/www/html && php -d memory_limit=512M bin/console prestashop:module install $(MODULE_NAME)"
	$(MAKE) configure TWO_API_KEY=$(or $(TWO_API_KEY),dummy-dev-key) TWO_ENVIRONMENT=$(TWO_ENVIRONMENT)
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash /var/www/html/modules/$(MODULE_NAME)/dev/patch-proxy "$$PROXY_URL"; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " PrestaShop store: $(URL)"; \
	echo " Admin panel:      $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:     $$PROXY_URL/"; \
		echo " Proxy admin:     $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:      $(ADMIN_MAIL) / $(ADMIN_PASSWD)"; \
	echo "========================================="

## Update Two payment config: make configure TWO_API_KEY=xxx
configure:
	docker exec $(CONTAINER) php -r " \
		define('_PS_ADMIN_DIR_', '/var/www/html/admin'); \
		require '/var/www/html/config/config.inc.php'; \
		Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '$(TWO_API_KEY)'); \
		Configuration::updateValue('PS_TWO_ENVIRONMENT', '$(TWO_ENVIRONMENT)'); \
		echo 'Two config updated' . PHP_EOL;"
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
	echo " Admin panel:      $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:     $$PROXY_URL/"; \
		echo " Proxy admin:     $$PROXY_URL/admin"; \
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

## Create a versioned zip archive
archive:
	git archive --format zip --prefix=$(MODULE_NAME)/ HEAD > $(MODULE_NAME).zip
