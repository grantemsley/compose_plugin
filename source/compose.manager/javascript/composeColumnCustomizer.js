/**
 * Column customization for stack + service tables.
 * Preferences are persisted server-side in a JSON file.
 */
(function() {
    'use strict';

    var STACK_COLS = {
        cpu: 'CPU %',
        memory: 'Memory',
        net_io: 'Network I/O',
        block_io: 'Disk I/O',
        description: 'Description',
        path: 'Path'
    };

    var SERVICE_COLS = {
        cpu: 'CPU %',
        memory: 'Memory',
        net_io: 'Network I/O',
        block_io: 'Disk I/O',
        source: 'Source',
        tag: 'Tag',
        net: 'Network',
        ip: 'IP'
    };

    var defaults = {
        stack: {
            cpu: true,
            memory: true,
            net_io: false,
            block_io: false,
            description: true,
            path: true
        },
        service: {
            cpu: true,
            memory: true,
            net_io: false,
            block_io: false,
            source: true,
            tag: true,
            net: true,
            ip: true
        }
    };

    var prefs = {
        stack: $.extend({}, defaults.stack),
        service: $.extend({}, defaults.service)
    };

    var STACK_WIDTH_WEIGHTS = {
        name: 23,
        update: 16,
        containers: 8,
        uptime: 9,
        cpu: 10,
        memory: 13,
        net_io: 10,
        block_io: 10,
        description: 14,
        path: 12,
        autostart: 8
    };

    var STACK_DEFAULT_VISIBLE = {
        name: true,
        update: true,
        containers: true,
        uptime: true,
        autostart: true,
        cpu: true,
        memory: true,
        net_io: false,
        block_io: false,
        description: true,
        path: true
    };

    function normalizePrefs(incoming) {
        var out = {
            stack: $.extend({}, defaults.stack),
            service: $.extend({}, defaults.service)
        };
        if (!incoming || typeof incoming !== 'object') return out;

        ['stack', 'service'].forEach(function(scope) {
            if (!incoming[scope] || typeof incoming[scope] !== 'object') return;
            Object.keys(out[scope]).forEach(function(key) {
                if (Object.prototype.hasOwnProperty.call(incoming[scope], key)) {
                    out[scope][key] = !!incoming[scope][key];
                }
            });
        });
        return out;
    }

    function applyScope(scope) {
        var selector = scope === 'stack' ? '#compose_stacks' : '.compose-ct-table';
        var $tables = $(selector);
        Object.keys(prefs[scope]).forEach(function(col) {
            $tables.toggleClass('hide-col-' + col, !prefs[scope][col]);
        });
        if (scope === 'stack') {
            applyStackWidthMath();
            syncStackColspans();
        }
        forceTableLayoutReflow($tables);
    }

    // Number of physically rendered stack columns (arrow, icon, and the five
    // always-on columns are fixed; the rest depend on visibility prefs).
    function getVisibleStackColCount() {
        var count = 7; // arrow, icon, name, update, containers, uptime, autostart
        Object.keys(STACK_COLS).forEach(function(col) {
            if (prefs.stack[col]) count++;
        });
        return count;
    }

    // Full-width rows (detail rows, progress/empty/error rows) are authored with
    // a static colspan that assumes every column exists. Under table-layout:fixed
    // an oversized colspan keeps the display:none columns' slots alive, so they
    // steal width from the visible columns. Clamp colspan to the live column count.
    function syncStackColspans() {
        var count = getVisibleStackColCount();
        $('#compose_stacks').find('td[colspan]').attr('colspan', count);
    }

    function applyStackWidthMath() {
        var $table = $('#compose_stacks');
        if (!$table.length) return;

        var visible = $.extend({}, STACK_DEFAULT_VISIBLE);
        Object.keys(STACK_COLS).forEach(function(col) {
            visible[col] = !!prefs.stack[col];
        });

        var totalWeight = 0;
        Object.keys(STACK_WIDTH_WEIGHTS).forEach(function(col) {
            if (visible[col]) {
                totalWeight += STACK_WIDTH_WEIGHTS[col];
            }
        });
        if (totalWeight <= 0) return;

        var tableEl = $table[0];
        Object.keys(STACK_WIDTH_WEIGHTS).forEach(function(col) {
            var fraction = visible[col] ? (STACK_WIDTH_WEIGHTS[col] / totalWeight) : 0;
            var cssVar = '--cm-col-' + col.replace(/_/g, '-') + '-frac';
            tableEl.style.setProperty(cssVar, String(fraction));
        });
    }

    function forceTableLayoutReflow($tables) {
        if (!$tables || !$tables.length) return;

        // Toggling display on columns already triggers a fixed-layout recompute;
        // we only need a read to flush it synchronously. No width mutation (that
        // caused a visible "snap"). Column widths come purely from CSS vars.
        $tables.each(function() {
            if (this) void this.offsetWidth;
        });
    }

    function applyAll() {
        applyScope('stack');
        applyScope('service');
    }

    function getScopeMap(scope) {
        return scope === 'stack' ? STACK_COLS : SERVICE_COLS;
    }

    function scopeSelectId(scope, side) {
        return '#compose-col-' + scope + '-' + side;
    }

    function renderScopeTransfer(scope) {
        var map = getScopeMap(scope);
        var $hidden = $(scopeSelectId(scope, 'hidden'));
        var $visible = $(scopeSelectId(scope, 'visible'));

        if (!$hidden.length || !$visible.length) return;

        var hiddenHtml = '';
        var visibleHtml = '';

        Object.keys(map).forEach(function(col) {
            var optionHtml = '<option value="' + col + '">' + map[col] + '</option>';
            if (prefs[scope] && prefs[scope][col]) {
                visibleHtml += optionHtml;
            } else {
                hiddenHtml += optionHtml;
            }
        });

        if (!hiddenHtml) {
            hiddenHtml = '<option value="" disabled>(none)</option>';
        }
        if (!visibleHtml) {
            visibleHtml = '<option value="" disabled>(none)</option>';
        }

        $hidden.html(hiddenHtml);
        $visible.html(visibleHtml);
    }

    function renderTransferLists() {
        renderScopeTransfer('stack');
        renderScopeTransfer('service');
    }

    function setScopeColumnVisibility(scope, keys, isVisible) {
        if (!keys || !keys.length || !prefs[scope]) return;

        keys.forEach(function(col) {
            if (Object.prototype.hasOwnProperty.call(prefs[scope], col)) {
                prefs[scope][col] = isVisible;
            }
        });

        applyScope(scope);
        renderScopeTransfer(scope);
    }

    function getSelectedTransferKeys(scope, side) {
        var values = $(scopeSelectId(scope, side)).val();
        if (!values) return [];
        return Array.isArray(values) ? values : [values];
    }

    function moveSelected(scope, toVisible) {
        var side = toVisible ? 'hidden' : 'visible';
        var keys = getSelectedTransferKeys(scope, side).filter(function(key) {
            return key !== '';
        });
        setScopeColumnVisibility(scope, keys, toVisible);
    }

    function moveAll(scope, toVisible) {
        var map = getScopeMap(scope);
        var keys = [];

        Object.keys(map).forEach(function(col) {
            var currentlyVisible = !!(prefs[scope] && prefs[scope][col]);
            if (toVisible && !currentlyVisible) keys.push(col);
            if (!toVisible && currentlyVisible) keys.push(col);
        });

        setScopeColumnVisibility(scope, keys, toVisible);
    }

    function buildTransferSection(scope, title) {
        var html = '<div class="compose-col-section">';
        html += '<div class="compose-col-section-title">' + title + '</div>';
        html += '<div class="compose-transfer-wrap">';
        html += '<div class="compose-transfer-col">';
        html += '<label for="compose-col-' + scope + '-hidden">Hidden</label>';
        html += '<select id="compose-col-' + scope + '-hidden" class="compose-transfer-select" multiple></select>';
        html += '</div>';
        html += '<div class="compose-transfer-actions">';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="selected-right" title="Show selected" aria-label="Show selected">&gt;</div>';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="all-right" title="Show all" aria-label="Show all">&gt;&gt;</div>';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="selected-left" title="Hide selected" aria-label="Hide selected">&lt;</div>';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="all-left" title="Hide all" aria-label="Hide all">&lt;&lt;</div>';
        html += '</div>';
        html += '<div class="compose-transfer-col">';
        html += '<label for="compose-col-' + scope + '-visible">Visible</label>';
        html += '<select id="compose-col-' + scope + '-visible" class="compose-transfer-select" multiple></select>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function buildModal() {
        var html = '<div id="compose-col-settings-modal" class="compose-col-modal" style="display:none;">';
        html += '<div class="compose-modal-overlay"></div>';
        html += '<div class="compose-modal-content">';
        html += '<div class="compose-modal-header"><span>Column Visibility</span>';
        html += '<button class="compose-modal-close" type="button" onclick="composeColCustomizer.closeModal();"><i class="fa fa-times"></i></button>';
        html += '</div>';
        html += '<div class="compose-modal-body">';
        html += buildTransferSection('stack', 'Stack Columns');
        html += buildTransferSection('service', 'Service Columns');
        html += '</div>';
        html += '<div class="compose-modal-footer">';
        html += '<button class="compose-modal-btn compose-modal-btn-save" type="button" onclick="composeColCustomizer.saveAndClose();">Save</button>';
        html += '<button class="compose-modal-btn compose-modal-btn-cancel" type="button" onclick="composeColCustomizer.closeModal();">Cancel</button>';
        html += '</div></div></div>';
        return html;
    }

    function syncModalFromPrefs() {
        renderTransferLists();
    }

    function fetchPrefs(cb) {
        $.post(caURL, {
            action: 'getColumnVisibility'
        }, function(resp) {
            var parsed = resp;
            if (typeof parsed === 'string') {
                try {
                    parsed = JSON.parse(parsed);
                } catch (e) {
                    parsed = null;
                }
            }
            if (parsed && parsed.result === 'success' && parsed.visibility) {
                prefs = normalizePrefs(parsed.visibility);
            } else {
                prefs = normalizePrefs(null);
            }
            if (typeof cb === 'function') cb();
        }).fail(function() {
            prefs = normalizePrefs(null);
            if (typeof cb === 'function') cb();
        });
    }

    function savePrefs(cb) {
        $.post(caURL, {
            action: 'saveColumnVisibility',
            visibility: JSON.stringify(prefs)
        }, function(resp) {
            var parsed = resp;
            if (typeof parsed === 'string') {
                try {
                    parsed = JSON.parse(parsed);
                } catch (e) {
                    parsed = null;
                }
            }
            if (parsed && parsed.result === 'success' && parsed.visibility) {
                prefs = normalizePrefs(parsed.visibility);
            }
            if (typeof cb === 'function') cb();
        }).fail(function() {
            if (typeof cb === 'function') cb();
        });
    }

    window.composeColCustomizer = {
        init: function() {
            if (!$('#compose-col-settings-modal').length) {
                $('body').append(buildModal());
            }

            $(document).on('click', '.compose-transfer-btn', function() {
                var scope = $(this).data('scope');
                var action = $(this).data('action');

                if (!scope || !action) return;

                if (action === 'selected-right') moveSelected(scope, true);
                if (action === 'all-right') moveAll(scope, true);
                if (action === 'selected-left') moveSelected(scope, false);
                if (action === 'all-left') moveAll(scope, false);
            });

            $(document).on('keydown', '.compose-transfer-btn', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                $(this).trigger('click');
            });

            this.addToolbarButton();
            fetchPrefs(function() {
                syncModalFromPrefs();
                applyAll();
            });
        },

        reapply: function() {
            applyAll();
        },

        syncColspans: function() {
            syncStackColspans();
        },

        openModal: function() {
            syncModalFromPrefs();
            $('#compose-col-settings-modal').fadeIn(150);
            $('body').css('overflow', 'hidden');
        },

        closeModal: function() {
            $('#compose-col-settings-modal').fadeOut(150);
            $('body').css('overflow', '');
        },

        saveAndClose: function() {
            var self = this;
            savePrefs(function() {
                applyAll();
                self.closeModal();
            });
        },

        addToolbarButton: function() {
            if ($('#compose-col-launcher-wrap').length) return;

            var launcherHtml = '' +
                '<div id="compose-col-launcher-wrap" class="ToggleViewMode compose-col-launcher-wrap">' +
                '<a href="#" class="compose-col-launcher" title="Customize visible columns" onclick="event.preventDefault(); composeColCustomizer.openModal();">' +
                '<i class="fa fa-sliders fa-rotate-90" aria-hidden="true"></i>' +
                '<span>Columns</span>' +
                '</a>' +
                '</div>';

            var $launcher = $(launcherHtml);
            var $tableWrapper = $('#compose_stacks').closest('.TableContainer');
            if ($tableWrapper.length) {
                $tableWrapper.before($launcher);
            } else if ($('#compose_stacks').length) {
                $('#compose_stacks').before($launcher);
            } else if ($('.tabs').length) {
                $('.tabs').append($launcher);
            } else {
                $('body').prepend($launcher);
            }
        }
    };

    $(function() {
        window.composeColCustomizer.init();
    });
})();
