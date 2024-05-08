import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PodsIndexRoute extends Route {
    @service fetch;
    @service appCache;

    queryParams = {
        query: {
            refreshModel: true,
        },
    };

    model(params) {
        return this.fetch.get('pods', params, { namespace: 'solid/int/v1' });
    }

    afterModel(model) {
        this.appCache.set('solid:pods', model);
    }
}
