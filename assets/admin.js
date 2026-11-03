/**
 * Odds Comparison admin: availability checks and cache flush through the
 * WordPress REST API (wp.apiFetch adds the root URL and the nonce).
 */
(() => {
    const cfg = window.oddscmpAdmin;
    const apiFetch = window.wp && window.wp.apiFetch;

    if (!cfg || !apiFetch) {
        return;
    }

    const statusLine = document.querySelector('.oddscmp-status');

    const say = (text) => {
        if (statusLine) {
            statusLine.textContent = text;
        }
    };

    const post = (path) => apiFetch({ path: `/${cfg.restNamespace}${path}`, method: 'POST' });

    const messageOf = (error) => (error && error.message) || cfg.failed;

    /** Write a status payload into its table row. */
    const paint = (id, status) => {
        const row = document.querySelector(`tr[data-source="${CSS.escape(id)}"]`);
        if (!row) {
            return;
        }

        const badge = row.querySelector('[data-role="badge"]');
        badge.textContent = status.label;
        badge.className = `oddscmp-badge oddscmp-badge--${status.state}`;
        row.querySelector('[data-role="message"]').textContent = status.message;
        row.querySelector('[data-role="checked"]').textContent = status.checked;
        row.querySelector('[data-role="latency"]').textContent = status.latencyMs !== null ? `${status.latencyMs} ms` : '';
    };

    const setDisabled = (buttons, disabled) => buttons.forEach((b) => { b.disabled = disabled; });

    document.querySelectorAll('[data-check-source]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = button.dataset.checkSource;
            const buttons = [...document.querySelectorAll('[data-check-source]')];
            const badges = target === 'all'
                ? [...document.querySelectorAll('[data-role="badge"]')]
                : [document.querySelector(`tr[data-source="${CSS.escape(target)}"] [data-role="badge"]`)];

            setDisabled(buttons, true);
            say(cfg.checking);
            badges.filter(Boolean).forEach((badge) => { badge.textContent = cfg.checking; });

            try {
                const result = await post(`/sources/${encodeURIComponent(target)}/check`);
                Object.entries(result).forEach(([id, status]) => paint(id, status));
                say('');
            } catch (error) {
                say(messageOf(error));
            } finally {
                setDisabled(buttons, false);
            }
        });
    });

    // Detect the bookmakers a source provides, then reload to list them.
    document.querySelectorAll('[data-detect-bookmakers]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            say(cfg.detecting);

            try {
                await post(`/sources/${encodeURIComponent(button.dataset.detectBookmakers)}/bookmakers/detect`);
                window.location.reload();
            } catch (error) {
                say(messageOf(error));
                button.disabled = false;
            }
        });
    });

    const flush = document.getElementById('oddscmp-flush');
    if (flush) {
        flush.addEventListener('click', async () => {
            flush.disabled = true;
            try {
                await post('/cache/flush');
                say(cfg.flushed);
            } catch (error) {
                say(messageOf(error));
            } finally {
                flush.disabled = false;
            }
        });
    }
})();
