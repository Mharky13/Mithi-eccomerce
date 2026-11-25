(() => {
    const navScriptUrl = document.currentScript.src;
    const frontendRootUrl = new URL('../', navScriptUrl);

    fetch(new URL('nav_bar.html', navScriptUrl))
        .then(response => {
            if (!response.ok) {
                throw new Error('Navigation failed to load');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('navbar-container').innerHTML = html.replace(
                /\{\{FRONTEND_ROOT\}\}/g,
                frontendRootUrl.href
            );
            initNavBar();

            if (typeof syncBadge === 'function') {
                syncBadge();
            }
        })
        .catch(error => {
            console.error('Error loading navigation:', error);
        });
})();