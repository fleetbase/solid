import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ApplicationController extends Controller {
    @service universe;
    @service fetch;

    constructor() {
        super(...arguments);
        this.universe.on('sidebarContext.available', (sidebarContext) => {
            sidebarContext.hideNow();
        });
    }

    @task *authenticate() {
        const { authenticationUrl, identifier } = yield this.fetch.get('request-authentication', {}, { namespace: 'solid/int/v1' });
        if (authenticationUrl) {
            window.location.href = `${authenticationUrl}/${identifier}`;
        }
    }

    @task *getAccountIndex() {
        const response = yield this.fetch.get('account', {}, { namespace: 'solid/int/v1' });
        console.log('[response]', response);
    }
}
