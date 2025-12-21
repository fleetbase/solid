import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { debug } from '@ember/debug';

export default class HomeController extends Controller {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service modalsManager;
    @tracked authStatus = null;
    @tracked cssCredentialsStatus = null;

    constructor() {
        super(...arguments);
        this.checkAuthenticationStatus.perform();
    }

    @task *checkAuthenticationStatus() {
        try {
            const authStatus = yield this.fetch.get('authentication-status', {}, { namespace: 'solid/int/v1' });
            this.authStatus = authStatus;
            
            // If authenticated, check CSS credentials
            if (authStatus.authenticated) {
                yield this.checkCssCredentials.perform();
            }
        } catch (error) {
            debug('Failed to check authentication status:' + error.message);
            this.authStatus = { authenticated: false, error: error.message };
        }
    }

    @task *checkCssCredentials() {
        try {
            const status = yield this.fetch.get('css-credentials/check', {}, { namespace: 'solid/int/v1' });
            this.cssCredentialsStatus = status;
            
            // If authenticated but no CSS credentials, show setup modal
            if (status.authenticated && !status.has_credentials) {
                this.showCssSetupModal();
            }
        } catch (error) {
            debug('Failed to check CSS credentials:' + error.message);
        }
    }

    showCssSetupModal() {
        this.modalsManager.show('modals/setup-css-credentials', {
            title: 'Setup CSS Account Credentials',
            acceptButtonText: 'Setup Credentials',
            acceptButtonIcon: 'check',
            cssEmail: '',
            cssPassword: '',
            error: null,
            serverUrl: this.serverUrl,
            confirm: (modal) => {
                modal.startLoading();
                return this.setupCssCredentials.perform(modal.getOptions('cssEmail'), modal.getOptions('cssPassword'), modal);
            },
        });
    }

    @task *setupCssCredentials(email, password, modal) {
        try {
            const response = yield this.fetch.post('css-credentials/setup', {
                email,
                password,
            }, { namespace: 'solid/int/v1' });
            
            if (response.success) {
                this.notifications.success('CSS credentials configured successfully!');
                yield this.checkCssCredentials.perform();
                modal.done();
            } else {
                modal.setOption('error', response.error || 'Failed to setup credentials');
                modal.stopLoading();
            }
        } catch (error) {
            modal.setOption('error', error.message || 'An error occurred');
            modal.stopLoading();
        }
    }

    @task *authenticate() {
        try {
            const { authenticationUrl, identifier } = yield this.fetch.get('request-authentication', {}, { namespace: 'solid/int/v1' });
            if (authenticationUrl) {
                window.location.href = `${authenticationUrl}/${identifier}`;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *getAccountIndex() {
        yield this.fetch.get('account', {}, { namespace: 'solid/int/v1' });
    }

    @task *logout() {
        try {
            yield this.fetch.post('logout', {}, { namespace: 'solid/int/v1' });
            this.notifications.success('Logged out successfully');
            this.authStatus = { authenticated: false };
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *refreshStatus() {
        yield this.checkAuthenticationStatus.perform();
    }

    @task *navigateToPods() {
        this.hostRouter.transitionTo('console.solid-protocol.pods');
    }

    @task *navigateToAccount() {
        this.hostRouter.transitionTo('console.solid-protocol.account');
    }

    get isAuthenticated() {
        return this.authStatus?.authenticated === true;
    }

    get userProfile() {
        return this.authStatus?.profile?.parsed_profile || {};
    }

    get webId() {
        return this.authStatus?.profile?.webid;
    }

    get userName() {
        return this.userProfile.name || 'Unknown User';
    }

    get userEmail() {
        return this.userProfile.email || 'No email available';
    }

    get serverUrl() {
        // Extract server URL from webId or use default
        const webId = this.webId;
        if (webId) {
            try {
                const url = new URL(webId);
                return `${url.protocol}//${url.host}`;
            } catch (e) {
                // Fallback
            }
        }
        return 'http://localhost:3000';
    }

    get hasCssCredentials() {
        return this.cssCredentialsStatus?.has_credentials === true;
    }

    get storageLocations() {
        return this.userProfile.storage_locations || [];
    }

    get hasStorageLocations() {
        return this.storageLocations.length > 0;
    }
}
