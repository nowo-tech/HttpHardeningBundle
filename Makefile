# Makefile for HTTP Hardening
# All dev targets use the root docker-compose.yml.

# Prefer Compose V2 plugin; fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_FILE := docker-compose.yml
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help ensure-up up down build shell install assets test test-coverage coverage-check test-coverage-100 \
        cs-check cs-fix rector rector-dry phpstan qa release-check composer-sync clean update update-deps validate \
        setup-hooks check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history

help:
	@echo "HTTP Hardening - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up            Start Docker container"
	@echo "  down          Stop Docker container"
	@echo "  build         Rebuild Docker image (no cache)"
	@echo "  shell         Open shell in container"
	@echo "  install       Install Composer dependencies"
	@echo "  assets        No-op (no frontend in this bundle)"
	@echo "  test          Run PHPUnit tests (starts container if needed)"
	@echo "  test-coverage Run tests with code coverage (starts container if needed)"
	@echo "  coverage-check Fail when PHP statement coverage is below 99 percent"
	@echo "  test-coverage-100 Fail unless PHP statement coverage is 100 percent"
	@echo "  cs-check      Check code style"
	@echo "  cs-fix        Fix code style"
	@echo "  rector        Apply Rector refactoring"
	@echo "  rector-dry    Run Rector in dry-run mode"
	@echo "  phpstan       Run PHPStan static analysis"
	@echo "  igor          Run Igor worker-state audit (REQ-CS-008)"
	@echo "  qa            Run all QA checks (cs-check + test)"
	@echo "  release-check Pre-release pipeline"
	@echo "  composer-sync Validate composer.json and align composer.lock (no install)"
	@echo "  clean         Remove vendor and cache"
	@echo "  update        Update composer.lock in the bundle container"
	@echo "  update-deps   Update bundle Composer lock (no demos in this package)"
	@echo "  validate      Run composer validate --strict"
	@echo "  setup-hooks   Install .githooks (REQ-GIT-001)"
	@echo "  check-no-cursor-coauthor  Fail if Cursor co-author trailers in history"
	@echo "  check-open-prs Fail if GitHub has unresolved open pull requests"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	@echo "Dependencies installed."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (root docker-compose)..."; \
		$(COMPOSE) up -d --build; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt
	./.scripts/coverage-check.sh coverage.xml 99

coverage-check: test-coverage

test-coverage-100: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt
	./.scripts/coverage-check.sh coverage.xml 100

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

update-deps: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict


# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor
release-check: check-no-cursor-coauthor check-open-prs ensure-up composer-sync cs-fix cs-check rector-dry phpstan igor test-coverage

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle."

setup-hooks:
	@mkdir -p .git/hooks
	@if [ -f .githooks/pre-commit ]; then \
		cp -f .githooks/pre-commit .git/hooks/pre-commit; \
		chmod +x .git/hooks/pre-commit; \
	fi
	@if [ -f .githooks/commit-msg ]; then \
		cp -f .githooks/commit-msg .git/hooks/commit-msg; \
		chmod +x .git/hooks/commit-msg; \
	fi
	@git config core.hooksPath .githooks
	@echo "Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps — bundle container only (no demos).
# REQ-MAKE-009: no hard dependency on paths outside this git repository.
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@./.scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
