<?php
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");

// Allow callers to override the socket name via query parameter
// (used by per-container console/logs). Sanitise to alphanumeric + _ and -.
$active_socket = $socket_name;
if (!empty($_GET['socket'])) {
    $active_socket = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['socket']);
}
$showDone = !empty($_GET['done']);

$url = "/logterminal/$active_socket/";

$version = parse_ini_file("/etc/unraid-version");
if (version_compare($version['version'], "6.10.0", "<")) {
    $url = "/dockerterminal/$socket_name/";
}
?>
<!DOCTYPE html>
<html style="height:100%;margin:0;padding:0">

<head>
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            background: rgb(43, 43, 43)
        }

        body {
            display: flex;
            flex-direction: column
        }

        #ttyd-frame {
            flex: 1;
            border: none;
            width: 100%;
            display: block
        }

        p.centered {
            text-align: center;
            padding: 12px 0 20px;
            margin: 0;
            background: rgb(43, 43, 43);
            flex-shrink: 0
        }

        p.centered button {
            margin: 0
        }

        input[type=button],
        input[type=reset],
        input[type=submit],
        button,
        button[type=button],
        a.button {
            font-family: clear-sans;
            font-size: 1.1rem;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 9px 18px;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            outline: none;
            border-radius: 4px;
            border: 0;
            color: #ff8c2f;
            background: -webkit-gradient(linear, left top, right top, from(#e22828), to(#ff8c2f)) 0 0 no-repeat, -webkit-gradient(linear, left top, right top, from(#e22828), to(#ff8c2f)) 0 100% no-repeat, -webkit-gradient(linear, left bottom, left top, from(#e22828), to(#e22828)) 0 100% no-repeat, -webkit-gradient(linear, left bottom, left top, from(#ff8c2f), to(#ff8c2f)) 100% 100% no-repeat;
            background: linear-gradient(90deg, #e22828 0, #ff8c2f) 0 0 no-repeat, linear-gradient(90deg, #e22828 0, #ff8c2f) 0 100% no-repeat, linear-gradient(0deg, #e22828 0, #e22828) 0 100% no-repeat, linear-gradient(0deg, #ff8c2f 0, #ff8c2f) 100% 100% no-repeat;
            background-size: 100% 2px, 100% 2px, 2px 100%, 2px 100%
        }

        input:hover[type=button],
        input:hover[type=reset],
        input:hover[type=submit],
        button:hover,
        button:hover[type=button],
        a.button:hover {
            color: #f2f2f2;
            background: -webkit-gradient(linear, left top, right top, from(#e22828), to(#ff8c2f));
            background: linear-gradient(90deg, #e22828 0, #ff8c2f)
        }
    </style>
</head>

<body>
    <iframe id="ttyd-frame" src="<?= $url ?>"></iframe>
    <?php if ($showDone): ?>
        <p class="centered"><button class="logLine" type="button" id="done-btn">Done</button></p>
    <?php endif; ?>
    <script>
        <?php if ($showDone): ?>
            // Done button: close Shadowbox (if inside one) or close the window
            document.getElementById('done-btn').addEventListener('click', function() {
                try {
                    top.Shadowbox.close();
                } catch (e) {}
                try {
                    window.close();
                } catch (e) {}
            });
        <?php endif; ?>

        // Aggressively suppress "Leave Site?" prompt from ttyd's beforeunload handler.
        // ttyd sets window.onbeforeunload after its JS loads, so we must continuously
        // override it using an interval + Object.defineProperty on the iframe window.
        function suppressBeforeUnload(win) {
            try {
                // Prevent ttyd from setting onbeforeunload by making it a no-op property
                Object.defineProperty(win, 'onbeforeunload', {
                    get: function() {
                        return null;
                    },
                    set: function() {
                        /* swallow ttyd's assignment */ },
                    configurable: true
                });
                // Also catch addEventListener-based handlers
                var origAdd = win.addEventListener.bind(win);
                win.addEventListener = function(type, fn, opts) {
                    if (type === 'beforeunload') return;
                    return origAdd(type, fn, opts);
                };
            } catch (e) {}
        }

        // Apply to parent window
        suppressBeforeUnload(window);

        // Apply inside iframe once loaded (same-origin)
        var frame = document.getElementById('ttyd-frame');
        frame.addEventListener('load', function() {
            try {
                suppressBeforeUnload(frame.contentWindow);
                // Inject scrollbar styling
                var fdoc = frame.contentDocument || frame.contentWindow.document;
                var s = fdoc.createElement('style');
                s.textContent = '::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:rgba(255,255,255,0.05);border-radius:4px}::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.2);border-radius:4px}::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.35)}';
                fdoc.head.appendChild(s);
                // Force ttyd to recalculate terminal dimensions (fixes initial right margin)
                setTimeout(function() {
                    frame.contentWindow.dispatchEvent(new Event('resize'));
                }, 200);
            } catch (e) {}
        });
    </script>
</body>

</html>
