# Makefile for mod_adele
#
# Fix G.7 (Session 003): mod_adele had no build tooling at all, so release
# ZIPs were built by hand (raw folder export) — the same class of problem
# confirmed for local_adele's package during Session 003 review
# (full .git/ directory, Windows Zone.Identifier files). Packaging only;
# does not port enrol_adele's full CI-mirroring Makefile.
#
# Targets:
#   make zip    — build an installable ZIP in build/
#   make clean  — remove build artefacts
#   make link   — symlink this checkout into MOODLE_ROOT/mod/adele
#   make unlink — remove that symlink
#
# Paths are auto-detected from the makefile's own location — the plugin
# lives at <MOODLE_ROOT>/mod/adele/, two levels below the Moodle root.
# Override on the command line if necessary:
#   make zip MOODLE_ROOT=/opt/moodle

THIS_DIR      := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR    ?= $(THIS_DIR)
MOODLE_ROOT   ?= $(abspath $(PLUGIN_DIR)/../..)
PLUGIN_NAME   ?= mod_adele
PLUGIN_REL    ?= mod/adele
PLUGIN_BASENAME := $(notdir $(PLUGIN_REL))
VERSION       := $(shell sed -n "s/^\$$plugin->release *= *'\(.*\)';/\1/p" $(PLUGIN_DIR)/version.php)

BUILD_DIR     := $(PLUGIN_DIR)/build
ZIP_NAME      := $(PLUGIN_NAME)-$(VERSION).zip
DIST_CONTENT  := backup classes db lang pix tests \
                 index.php lib.php mod_form.php version.php view.php \
                 README.md LICENSE.md

.PHONY: help zip clean link unlink

help: ## List available targets.
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

zip: clean ## Build an installable ZIP in build/.
	@mkdir -p $(BUILD_DIR)/$(PLUGIN_BASENAME)
	@cp -r $(addprefix $(PLUGIN_DIR)/,$(DIST_CONTENT)) $(BUILD_DIR)/$(PLUGIN_BASENAME)/
	@cd $(BUILD_DIR) && zip -rq $(ZIP_NAME) $(PLUGIN_BASENAME) \
		-x '*.DS_Store' -x '*/.git/*' -x '*Zone.Identifier*'
	@rm -rf $(BUILD_DIR)/$(PLUGIN_BASENAME)
	@echo "Built $(BUILD_DIR)/$(ZIP_NAME)"
	@echo "Install via Site administration > Plugins > Install plugins."

clean: ## Remove build artefacts.
	@rm -rf $(BUILD_DIR)

link: ## Symlink this checkout into MOODLE_ROOT/mod/adele.
	@test -d "$(MOODLE_ROOT)" || { echo "MOODLE_ROOT '$(MOODLE_ROOT)' not found."; exit 1; }
	@test -e "$(MOODLE_ROOT)/$(PLUGIN_REL)" \
		&& { echo "$(MOODLE_ROOT)/$(PLUGIN_REL) already exists."; exit 1; } || true
	@ln -s "$(PLUGIN_DIR)" "$(MOODLE_ROOT)/$(PLUGIN_REL)"
	@echo "Linked $(PLUGIN_DIR) -> $(MOODLE_ROOT)/$(PLUGIN_REL)"

unlink: ## Remove the MOODLE_ROOT symlink created by 'make link'.
	@test -L "$(MOODLE_ROOT)/$(PLUGIN_REL)" && rm "$(MOODLE_ROOT)/$(PLUGIN_REL)" \
		&& echo "Unlinked $(MOODLE_ROOT)/$(PLUGIN_REL)" \
		|| echo "$(MOODLE_ROOT)/$(PLUGIN_REL) is not a symlink, left untouched."
