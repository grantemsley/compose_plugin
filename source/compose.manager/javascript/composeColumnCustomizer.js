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
    }

    function applyAll() {
        applyScope('stack');
        applyScope('service');
    }

    function buildChecklist(scope, map) {
        var html = '';
        Object.keys(map).forEach(function(col) {
            html += '<label class="compose-col-checkbox-label">';
            html += '<input type="checkbox" class="compose-col-checkbox" data-type="' + scope + '" data-col="' + col + '">';
            html += '<span>' + map[col] + '</span>';
            html += '</label>';
        });
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
        html += '<div class="compose-col-section"><div class="compose-col-section-title">Stack Columns</div><div class="compose-col-checklist" id="compose-stack-cols">' + buildChecklist('stack', STACK_COLS) + '</div></div>';
        html += '<div class="compose-col-section"><div class="compose-col-section-title">Service Columns</div><div class="compose-col-checklist" id="compose-service-cols">' + buildChecklist('service', SERVICE_COLS) + '</div></div>';
        html += '</div>';
        html += '<div class="compose-modal-footer">';
        html += '<button class="compose-modal-btn compose-modal-btn-save" type="button" onclick="composeColCustomizer.saveAndClose();">Save</button>';
        html += '<button class="compose-modal-btn compose-modal-btn-cancel" type="button" onclick="composeColCustomizer.closeModal();">Cancel</button>';
        html += '</div></div></div>';
        return html;
    }

    function syncModalFromPrefs() {
        $('.compose-col-checkbox').each(function() {
            var scope = $(this).data('type');
            var col = $(this).data('col');
            var checked = !!(prefs[scope] && prefs[scope][col]);
            $(this).prop('checked', checked);
        });
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

            $(document).on('change', '.compose-col-checkbox', function() {
                var scope = $(this).data('type');
                var col = $(this).data('col');
                if (!prefs[scope] || !Object.prototype.hasOwnProperty.call(prefs[scope], col)) return;
                prefs[scope][col] = $(this).is(':checked');
                applyScope(scope);
            });

            this.addHeaderGearIcon();
            fetchPrefs(function() {
                syncModalFromPrefs();
                applyAll();
            });
        },

        reapply: function() {
            applyAll();
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

        addHeaderGearIcon: function() {
            var $autostart = $('#compose_stacks thead th.col-autostart');
            if (!$autostart.length || $autostart.find('.compose-col-gear-icon').length) return;
            var gearHtml = '<a href="#" class="compose-col-gear-icon" title="Customize columns" onclick="event.preventDefault(); composeColCustomizer.openModal();"><i class="fa fa-sliders fa-rotate-90"></i></a>';
            $autostart.append(gearHtml);
        }
    };

    $(function() {
        window.composeColCustomizer.init();
    });
})();
