import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import esES from './snippet/es-ES.json';
import ruRU from './snippet/ru-RU.json';
import trTR from './snippet/tr-TR.json';
import zhCN from './snippet/zh-CN.json';

const { Component, Module } = Shopware;

const snippets = {
    'de-DE': deDE,
    'en-GB': enGB,
    'es-ES': esES,
    'ru-RU': ruRU,
    'tr-TR': trTR,
    'zh-CN': zhCN,
};

Component.register('paymos-connect-page', {
    template: `
        <sw-page class="paymos-connect-page">
            <template #smart-bar-header><h2>Paymos</h2></template>
            <template #content>
                <sw-card-view>
                    <sw-card :title="$tc('paymos.connect.cardTitle')">
                        <p>{{ $tc('paymos.connect.intro') }}</p>
                        <sw-button variant="primary" :disabled="busy" @click="start">{{ $tc('paymos.connect.button') }}</sw-button>
                        <p v-if="message" style="margin-top:12px">{{ message }}</p>
                        <p v-if="manualUrl" style="margin-top:12px;color:#b91c1c">
                            {{ $tc('paymos.connect.blocked') }}
                            <a :href="manualUrl" target="_blank" rel="noopener noreferrer">{{ $tc('paymos.connect.blockedLink') }}</a>
                            {{ $tc('paymos.connect.blockedCode', 0, { code: manualCode }) }}
                        </p>
                    </sw-card>
                    <sw-card title="TODO-COPY">
                        <sw-text-field :value="refundPaymentKey" label="TODO-COPY" @update:value="refundPaymentKey = $event" @input="refundPaymentKey = $event" />
                        <sw-button :disabled="busy || !refundPaymentKey" @click="refund('full')">TODO-COPY · full</sw-button>
                        <sw-button :disabled="busy || !refundPaymentKey" @click="refund('overpayment')">TODO-COPY · overpayment</sw-button>
                        <sw-button :disabled="busy || !refundPaymentKey" @click="refundStatus">TODO-COPY</sw-button>
                        <pre v-if="refundResult">{{ refundResult }}</pre>
                    </sw-card>
                </sw-card-view>
            </template>
        </sw-page>`,
    data() { return { busy: false, message: '', manualUrl: '', manualCode: '', refundPaymentKey: '', refundResult: '' }; },
    methods: {
        async refund(mode) {
            this.busy = true;
            const paymentKey = this.refundPaymentKey.trim();
            const key = `paymos:refund:${paymentKey}:${mode}`;
            try {
                let token = window.localStorage.getItem(key);
                if (!token) {
                    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
                    token = Array.from(bytes, (v) => v.toString(16).padStart(2, '0')).join('');
                    window.localStorage.setItem(key, token);
                }
                const result = await this.request(`/_action/paymos/refund/${encodeURIComponent(paymentKey)}/${mode}`, { request_token: token });
                this.refundResult = JSON.stringify(result, null, 2);
                if (['failed', 'cancelled'].includes(result.cash_state)) { window.localStorage.removeItem(key); }
            } catch (error) {
                this.refundResult = `TODO-COPY ${error.response?.data?.error || error.message}`;
            } finally { this.busy = false; }
        },
        async refundStatus() {
            this.busy = true;
            try {
                const client = Shopware.Application.getContainer('init').httpClient;
                const response = await client.get(`/_action/paymos/refund/${encodeURIComponent(this.refundPaymentKey.trim())}`);
                this.refundResult = JSON.stringify(response.data, null, 2);
            } catch (error) {
                this.refundResult = `TODO-COPY ${error.response?.data?.error || error.message}`;
            } finally { this.busy = false; }
        },
        async request(path, body) {
            const client = Shopware.Application.getContainer('init').httpClient;
            const response = await client.post(path, body);
            return response.data;
        },
        async start() {
            this.busy = true;
            this.manualUrl = '';
            this.message = this.$tc('paymos.connect.starting');

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
                    this.message = this.$tc('paymos.connect.waiting', 0, { code: result.user_code });
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
                        this.message = this.$tc('paymos.connect.connected');
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
    title: 'paymos.module.title',
    description: 'paymos.module.description',
    snippets,
    color: '#5a67d8',
    icon: 'regular-plug',
    routes: { index: { component: 'paymos-connect-page', path: 'index' } },
    settingsItem: { group: 'plugins', to: 'paymos.connect.index', icon: 'regular-plug' }
});
