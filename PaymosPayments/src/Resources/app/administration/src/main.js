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
                    </sw-card>
                </sw-card-view>
            </template>
        </sw-page>`,
    data() { return { busy: false, message: '' }; },
    methods: {
        async request(path) {
            const client = Shopware.Application.getContainer('init').httpClient;
            const response = await client.post(path);
            return response.data;
        },
        async start() {
            this.busy = true;
            this.message = 'Starting secure connection…';
            try {
                const result = await this.request('/_action/paymos/connect/start');
                window.open(result.verification_url, '_blank', 'noopener,noreferrer');
                this.message = `Waiting for approval. Code: ${result.user_code}`;
                this.poll(Math.max(1, Number(result.interval || 5)) * 1000);
            } catch (error) {
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
