.PHONY: warm-cache

PHP ?= php

# Usage:
#   make warm-cache ARGS="--county=VOL --address-ids=1,2 --party=ALL"
warm-cache:
	$(PHP) bin/warm_cache.php $(ARGS)

