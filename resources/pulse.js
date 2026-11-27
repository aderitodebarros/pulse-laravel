(() => {
    if (window.__pulseSdkInstalled) return;
    window.__pulseSdkInstalled = true;
    const nonce = document.currentScript?.nonce;
    let loading = false;
    const load = (source, ready) => {
        const script = document.createElement('script');
        script.src = source;
        if (nonce) script.nonce = nonce;
        script.onload = ready;
        script.onerror = () => {};
        document.head.appendChild(script);
    };
    const initialize = () => {
        const element = document.getElementById('pulse-sdk-config');
        if (!element) {
            if (window.__pulseSdkIdentity) window.PulseServer?.close();
            window.__pulseSdkIdentity = null;
            return;
        }
        let options;
        try { options = JSON.parse(element.textContent); } catch { return; }
        if (options.widget && !document.querySelector('pulseserver-feedback, feedbackserver-widget')) {
            const widget = document.createElement('pulseserver-feedback');
            widget.setAttribute('project-key', options.key);
            widget.setAttribute('api-base', options.apiBase);
            document.body.appendChild(widget);
            if (!customElements.get('pulseserver-feedback')) {
                load(options.apiBase + '/embed/feedbackserver-widget.js');
            }
        }
        if (!options.browserErrors) {
            if (window.__pulseSdkIdentity) window.PulseServer?.close();
            window.__pulseSdkIdentity = null;
            return;
        }
        const identity = JSON.stringify([options.apiBase, options.key, options.environment, options.release]);
        if (window.__pulseSdkIdentity === identity) return;
        const start = () => {
            try {
                window.__pulseSdkIdentity = identity;
                Promise.resolve(window.PulseServer.init({
                    apiBase: options.apiBase, key: options.key,
                    environment: options.environment, release: options.release,
                })).catch(() => { if (window.__pulseSdkIdentity === identity) window.__pulseSdkIdentity = null; });
            } catch {
                window.__pulseSdkIdentity = null;
            }
        };
        if (window.PulseServer) start();
        else if (!loading) {
            loading = true;
            load(options.apiBase + '/embed/pulseserver.js', () => { loading = false; initialize(); });
        }
    };
    document.addEventListener('livewire:navigated', initialize);
    initialize();
})();
