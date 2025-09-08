import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { debug } from '@ember/debug';

export default class HomeController extends Controller {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @tracked authStatus = null;

    constructor() {
        super(...arguments);
        this.checkAuthenticationStatus.perform();
    }

    @task *checkAuthenticationStatus() {
        try {
            const authStatus = yield this.fetch.get('authentication-status', {}, { namespace: 'solid/int/v1' });
            this.authStatus = authStatus;
        } catch (error) {
            debug('Failed to check authentication status:' + error.message);
            this.authStatus = { authenticated: false, error: error.message };
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

    get storageLocations() {
        return this.userProfile.storage_locations || [];
    }

    get hasStorageLocations() {
        return this.storageLocations.length > 0;
    }
}
