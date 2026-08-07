const { Component, Module } = Shopware;

Component.register('paymos-connect-page', {
    template: `
        <sw-page class="paymos-connect-page">
            <template #smart-bar-header><h2>Paymos</h2></template>
            <template #content>
                <sw-card-view>
                    <sw-card title="Connect Paymos">
                        <p>Connect this Shopware installation to the project currently selected in Paymos.</p>
                        <sw-button variant="primary" :disabled="busy" @click="start">Connect Paymos</sw-button>
                        <p v-if="message" style="margin-top:12px">{{ message }}</p>
                        <p v-if="manualUrl" style="margin-top:12px;color:#b91c1c">
                            Your browser blocked the approval tab.
                            <a :href="manualUrl" target="_blank" rel="noopener noreferrer">Open the approval page</a>
                            Code: {{ manualCode }}
                        </p>
                    </sw-card>
                </sw-card-view>
            </template>
        </sw-page>`,
    data() { return { busy: false, message: '', manualUrl: '', manualCode: '' }; },
    methods: {
        async request(path, body) {
            const client = Shopware.Application.getContainer('init').httpClient;
            const response = await client.post(path, body);
            return response.data;
        },
        async start() {
            this.busy = true;
            this.manualUrl = '';
            this.message = 'Starting secure connection…';

            // Opened synchronously: browsers only honour window.open for a few seconds
            // after the click, so opening it once the start request resolves is blocked
            // on slow connections. No feature string — any feature string asks for a
            // popup window, which blockers reject far more often than a plain tab.
            const tab = window.open('', '_blank');
            if (tab) {
                try { tab.opener = null; } catch (error) { /* cross-origin hardening only */ }
            }

            try {
                const result = await this.request('/_action/paymos/connect/start', {
                    paymos_return_url: window.location.href,
                });
                if (tab && !tab.closed) {
                    tab.location = result.verification_url;
                    this.message = `Waiting for approval. Code: ${result.user_code}`;
                } else {
                    // The link and code below are the merchant's only way to finish now.
                    this.message = '';
                    this.manualUrl = result.verification_url;
                    this.manualCode = result.user_code;
                }
                this.poll(Math.max(1, Number(result.interval || 5)) * 1000);
            } catch (error) {
                if (tab && !tab.closed) { tab.close(); }
                this.message = error.response?.data?.error || error.message;
                this.busy = false;
            }
        },
        poll(interval) {
            window.setTimeout(async () => {
                try {
                    const result = await this.request('/_action/paymos/connect/poll');
                    if (result.status === 'connected') {
                        this.message = 'Paymos connected.';
                        // Drop the recovery link: leaving it up tells a merchant who
                        // just connected that their browser blocked the approval tab.
                        this.manualUrl = '';
                        this.manualCode = '';
                        this.busy = false;
                        return;
                    }
                    this.poll(result.status === 'slow_down' ? interval + 5000 : interval);
                } catch (error) {
                    this.message = error.response?.data?.error || error.message;
                    this.busy = false;
                }
            }, interval);
        }
    }
});

Module.register('paymos-connect', {
    type: 'plugin',
    name: 'Paymos',
    title: 'Paymos',
    description: 'Connect Paymos',
    color: '#5a67d8',
    icon: 'regular-plug',
    routes: { index: { component: 'paymos-connect-page', path: 'index' } },
    settingsItem: { group: 'plugins', to: 'paymos.connect.index', icon: 'regular-plug' }
});
