import Route from '@ember/routing/route';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';
import { inject as service } from '@ember/service';

export default class ApplicationRoute extends Route {
    @service notifications;

    beforeModel(transition) {
        const queryParams = getWithDefault(transition, 'router._lastQueryParams', {});
        if (queryParams.error) {
            this.notifications.error(queryParams.error);
        }
    }
}
