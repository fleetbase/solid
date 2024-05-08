import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class HomeController extends Controller {
    @service fetch;
    @service notifications;

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
}
