import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class ApplicationController extends Controller {
    @service fetch;
    @service appCache;
    @tracked pods = [];

    constructor() {
        super(...arguments);
        this.getPods.perform();
    }

    @task *getPods() {
        yield timeout(600);

        if (this.appCache.has('solid:pods')) {
            this.pods = this.appCache.get('solid:pods', []);
            return;
        }

        try {
            this.pods = yield this.fetch.get('pods', {}, { namespace: 'solid/int/v1' });
        } catch (error) {
            // silence
        }
    }
}
